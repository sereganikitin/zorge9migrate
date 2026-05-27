<?php

namespace App\Controller;

use App\Entity\ImageBlock;
use App\Entity\MediaItem;
use App\Entity\TextBlock;
use App\Service\SectionInventory;
use App\Service\SectionLabels;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One page per landing section: shows every TextBlock and ImageBlock that
 * belongs to the section, with inline editing for text and inline file
 * upload for images. A single POST saves everything at once.
 */
#[IsGranted('ROLE_ADMIN')]
class SectionEditorController extends AbstractController
{
    public function __construct(
        private readonly SectionLabels $sectionLabels,
        private readonly SectionInventory $inventory,
        private readonly EntityManagerInterface $em,
        private readonly string $uploadsDir = '/var/www/cms-admin/public/uploads/media',
    ) {}

    #[Route('/section/{section}', name: 'section_editor', requirements: ['section' => '[a-z0-9-]+'])]
    public function edit(string $section, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->save($section, $request);
        }

        $texts = $this->loadTextBlocks($section);
        $images = $this->loadImageBlocks($section);

        return $this->render('section/editor.html.twig', [
            'section_id'    => $section,
            'section_label' => $this->sectionLabels->humanLabel($section),
            'section_icon'  => $this->sectionLabels->icon($section),
            'texts'         => $texts,
            'images'        => $images,
            'inventory'     => $this->inventory->counts(),
            'sections_meta' => SectionLabels::SECTIONS,
            'ordered_sections' => $this->inventory->nonEmptyOrdered($this->sectionLabels->orderedIds()),
            'has_unknown'   => $this->inventory->hasUnknown(),
        ]);
    }

    private function save(string $section, Request $request): RedirectResponse
    {
        $textRepo = $this->em->getRepository(TextBlock::class);
        $imageRepo = $this->em->getRepository(ImageBlock::class);

        $textsInput = $request->request->all('text');
        $resetTexts = $request->request->all('text_reset');
        $imageAltInput = $request->request->all('image_alt');
        $resetImages = $request->request->all('image_reset');

        $updated = 0;

        foreach ($textsInput as $id => $value) {
            $tb = $textRepo->find((int) $id);
            if (!$tb) continue;
            $value = (string) $value;
            $shouldReset = isset($resetTexts[$id]);
            // Don't store an override if it matches the default verbatim — keep `value` null.
            if ($shouldReset || $value === '' || trim($value) === trim(strip_tags((string) $tb->getDefaultValue()))) {
                if ($tb->getValue() !== null) {
                    $tb->setValue(null);
                    $updated++;
                }
                continue;
            }
            if ($tb->getValue() !== $value) {
                $tb->setValue($value);
                $updated++;
            }
        }

        // Image uploads
        $uploadedFiles = $request->files->all('image_file');
        foreach ($uploadedFiles as $id => $file) {
            if (!$file instanceof UploadedFile) continue;
            $ib = $imageRepo->find((int) $id);
            if (!$ib) continue;
            $meta = $this->moveUploadedFile($file);
            $mi = (new MediaItem())
                ->setFilename($meta['filename'])
                ->setOriginalName($meta['original_name'])
                ->setMimeType($meta['mime_type'])
                ->setSizeBytes($meta['size'])
                ->setAlt($imageAltInput[$id] ?? null);
            $this->em->persist($mi);
            $ib->setMedia($mi);
            if ($imageAltInput[$id] ?? null) {
                $ib->setAlt($imageAltInput[$id]);
            }
            $updated++;
        }

        // Image resets
        foreach ($resetImages as $id => $_) {
            $ib = $imageRepo->find((int) $id);
            if ($ib && $ib->getMedia() !== null) {
                $ib->setMedia(null);
                $updated++;
            }
        }

        // Alt-text-only edits (no file upload)
        foreach ($imageAltInput as $id => $alt) {
            if (isset($uploadedFiles[$id])) continue; // already handled above
            $ib = $imageRepo->find((int) $id);
            if (!$ib) continue;
            $alt = trim((string) $alt);
            if ($alt !== (string) $ib->getAlt()) {
                $ib->setAlt($alt ?: null);
                $updated++;
            }
        }

        $this->em->flush();

        $this->addFlash('success', sprintf('Сохранено. Изменено: %d', $updated));
        return $this->redirectToRoute('section_editor', ['section' => $section]);
    }

    /**
     * Move the uploaded file into the media directory and return the metadata
     * that the MediaItem entity needs.
     *
     * Metadata is captured BEFORE move(), because after move() the UploadedFile
     * object still references the temp path which no longer exists, and any
     * subsequent call (getMimeType / getSize / …) throws.
     *
     * @return array{filename:string, original_name:string, mime_type:string, size:int}
     */
    private function moveUploadedFile(UploadedFile $file): array
    {
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $base) ?: 'upload';
        $ext = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $newName = sprintf('%s-%s.%s', $base, bin2hex(random_bytes(4)), $ext);

        $meta = [
            'filename'      => $newName,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'size'          => (int) $file->getSize(),
        ];

        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0775, true);
        }
        $file->move($this->uploadsDir, $newName);
        return $meta;
    }

    /** @return list<TextBlock> */
    private function loadTextBlocks(string $section): array
    {
        $sql = <<<'SQL'
            SELECT id FROM text_block
            WHERE JSON_CONTAINS(sections, JSON_QUOTE(:s))
               OR (:is_unknown = 1 AND (JSON_CONTAINS(sections, '"unknown"') OR JSON_LENGTH(sections) = 0))
            ORDER BY id
        SQL;
        $ids = $this->em->getConnection()->fetchFirstColumn($sql, [
            's' => $section,
            'is_unknown' => $section === 'unknown' ? 1 : 0,
        ]);
        if (!$ids) return [];
        return $this->em->getRepository(TextBlock::class)->findBy(['id' => $ids], ['id' => 'ASC']);
    }

    /** @return list<ImageBlock> */
    private function loadImageBlocks(string $section): array
    {
        $sql = <<<'SQL'
            SELECT id FROM image_block
            WHERE JSON_CONTAINS(sections, JSON_QUOTE(:s))
               OR (:is_unknown = 1 AND (JSON_CONTAINS(sections, '"unknown"') OR JSON_LENGTH(sections) = 0))
            ORDER BY id
        SQL;
        $ids = $this->em->getConnection()->fetchFirstColumn($sql, [
            's' => $section,
            'is_unknown' => $section === 'unknown' ? 1 : 0,
        ]);
        if (!$ids) return [];
        return $this->em->getRepository(ImageBlock::class)->findBy(['id' => $ids], ['id' => 'ASC']);
    }
}
