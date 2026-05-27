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
    description: 'Upsert TextBlock + ImageBlock rows from the annotator manifest. Keys are content-hashed so the same text/image across pages maps to a single row; we just merge page_paths.',
)]
class SeedFromManifestCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('manifest', InputArgument::REQUIRED, 'Path to manifest JSON')
            ->addOption('dry-run', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('manifest');
        $dry = (bool) $input->getOption('dry-run');

        if (!is_file($path)) {
            $output->writeln("<error>manifest not found: $path</error>");
            return Command::FAILURE;
        }

        $entries = json_decode((string) file_get_contents($path), true);
        if (!is_array($entries)) {
            $output->writeln("<error>invalid manifest JSON</error>");
            return Command::FAILURE;
        }

        $textRepo = $this->em->getRepository(TextBlock::class);
        $imgRepo  = $this->em->getRepository(ImageBlock::class);

        $stats = ['text_new' => 0, 'text_updated_pages' => 0, 'img_new' => 0, 'img_updated_pages' => 0, 'skip_placeholder' => 0];

        foreach ($entries as $e) {
            $key = (string) ($e['key'] ?? '');
            $type = $e['type'] ?? null;
            $label = mb_substr((string) ($e['label'] ?? ''), 0, 200);
            $pagePaths = is_array($e['page_paths'] ?? null) ? $e['page_paths'] : [];

            if ($key === '' || $type === null) {
                continue;
            }

            $sections = is_array($e['sections'] ?? null) ? $e['sections'] : [];

            if ($type === 'image') {
                $defaultSrc = (string) ($e['default_src'] ?? '');
                if ($defaultSrc === '') {
                    $stats['skip_placeholder']++;
                    continue;
                }
                /** @var ImageBlock|null $existing */
                $existing = $imgRepo->findOneBy(['blockKey' => $key]);
                if ($existing) {
                    $before = $existing->getPagePaths();
                    foreach ($pagePaths as $p) {
                        $existing->addPagePath($p);
                    }
                    // Sections are pure derived data — overwrite to match the
                    // latest annotation, don't merge with stale entries.
                    $existing->setSections($sections);
                    if ($existing->getPagePaths() !== $before) {
                        $stats['img_updated_pages']++;
                    }
                } else {
                    $b = (new ImageBlock())
                        ->setBlockKey($key)
                        ->setLabel($label)
                        ->setDefaultSrc($defaultSrc)
                        ->setPagePaths($pagePaths)
                        ->setSections($sections);
                    if (!$dry) {
                        $this->em->persist($b);
                    }
                    $stats['img_new']++;
                }
                continue;
            }

            if ($type === 'text') {
                $defaultValue = (string) ($e['default_value'] ?? '');
                /** @var TextBlock|null $existing */
                $existing = $textRepo->findOneBy(['blockKey' => $key]);
                if ($existing) {
                    $before = $existing->getPagePaths();
                    foreach ($pagePaths as $p) {
                        $existing->addPagePath($p);
                    }
                    $existing->setSections($sections);
                    if ($existing->getPagePaths() !== $before) {
                        $stats['text_updated_pages']++;
                    }
                } else {
                    $b = (new TextBlock())
                        ->setBlockKey($key)
                        ->setLabel($label)
                        ->setDefaultValue($defaultValue)
                        ->setPagePaths($pagePaths)
                        ->setSections($sections);
                    if (!$dry) {
                        $this->em->persist($b);
                    }
                    $stats['text_new']++;
                }
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
