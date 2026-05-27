<?php

namespace App\Command;

use App\Entity\ImageBlock;
use App\Entity\TextBlock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:seed-from-manifest',
    description: 'Load TextBlock + ImageBlock rows from a JSON manifest produced by annotate_landing.py.',
)]
class SeedFromManifestCommand extends Command
{
    /** Pages we want to manage from CMS. Skip everything else (old site / promo / wolf snapshots). */
    private const ALLOWED_PAGES = [
        '',                 // homepage
        'apartments',
        'improvement',
        'infrastructure',
        'investment',
        'location',
        'management',
        'parking',
        'penthouses',
        'privacy-policy',
        'request',
        'services',
        'style',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('manifest', InputArgument::REQUIRED, 'Path to manifest JSON')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show counts, do not write');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('manifest');
        $dry = (bool) $input->getOption('dry-run');

        if (!is_file($path)) {
            $output->writeln("<error>manifest not found: $path</error>");
            return Command::FAILURE;
        }

        $raw = file_get_contents($path);
        $entries = json_decode($raw, true);
        if (!is_array($entries)) {
            $output->writeln("<error>invalid manifest JSON</error>");
            return Command::FAILURE;
        }

        $textRepo = $this->em->getRepository(TextBlock::class);
        $imgRepo = $this->em->getRepository(ImageBlock::class);

        $stats = ['skip_page' => 0, 'skip_placeholder' => 0, 'text_new' => 0, 'text_skip_existing' => 0,
                  'img_new' => 0, 'img_skip_existing' => 0];

        foreach ($entries as $e) {
            $page = $e['page'] ?? null;
            if ($page === null || !in_array($page, self::ALLOWED_PAGES, true)) {
                $stats['skip_page']++;
                continue;
            }

            $key = $e['key'] ?? null;
            $type = $e['type'] ?? null;
            $label = mb_substr((string) ($e['label'] ?? ''), 0, 200);
            if (!$key || !$type) {
                continue;
            }

            if ($type === 'image') {
                $defaultSrc = (string) ($e['default_src'] ?? '');
                if ($defaultSrc === '' ||
                    str_starts_with($defaultSrc, 'data:') ||
                    str_starts_with($defaultSrc, '<svg') ||
                    str_contains($defaultSrc, 'http://www.w3.org') ||
                    str_ends_with($defaultSrc, 'px.gif') ||
                    str_ends_with($defaultSrc, 'px-2x1.gif')
                ) {
                    $stats['skip_placeholder']++;
                    continue;
                }
                $existing = $imgRepo->findOneBy(['pagePath' => $page, 'blockKey' => $key]);
                if ($existing) {
                    $stats['img_skip_existing']++;
                    continue;
                }
                $b = (new ImageBlock())
                    ->setPagePath($page)
                    ->setBlockKey($key)
                    ->setLabel($label)
                    ->setDefaultSrc($defaultSrc);
                if (!$dry) {
                    $this->em->persist($b);
                }
                $stats['img_new']++;
                continue;
            }

            if ($type === 'text') {
                $defaultValue = (string) ($e['default_value'] ?? '');
                $existing = $textRepo->findOneBy(['pagePath' => $page, 'blockKey' => $key]);
                if ($existing) {
                    $stats['text_skip_existing']++;
                    continue;
                }
                $b = (new TextBlock())
                    ->setPagePath($page)
                    ->setBlockKey($key)
                    ->setLabel($label)
                    ->setDefaultValue($defaultValue);
                if (!$dry) {
                    $this->em->persist($b);
                }
                $stats['text_new']++;
            }
        }

        if (!$dry) {
            $this->em->flush();
        }

        foreach ($stats as $k => $v) {
            $output->writeln(sprintf('  %-25s %d', $k, $v));
        }
        return Command::SUCCESS;
    }
}
