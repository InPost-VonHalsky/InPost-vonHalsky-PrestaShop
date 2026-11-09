<?php
/**
 * MappingSuggestCommand
 *
 * Diagnostic CLI for the mapping assistant: prints the InPost suggestions
 * computed for every PrestaShop category (and, with --attributes, for the
 * attributes of mapped categories) - handy for tuning the matching.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Command;

use Inpost\VonHalsky\Service\Mapping\Suggest\AttributeAssistantService;
use Inpost\VonHalsky\Service\Mapping\Suggest\CategoryAssistantService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MappingSuggestCommand extends Command
{
    protected static $defaultName = 'inpost:mapping:suggest';

    private CategoryAssistantService $categoryAssistant;
    private AttributeAssistantService $attributeAssistant;

    public function __construct(CategoryAssistantService $categoryAssistant, AttributeAssistantService $attributeAssistant)
    {
        parent::__construct();
        $this->categoryAssistant = $categoryAssistant;
        $this->attributeAssistant = $attributeAssistant;
    }

    protected function configure(): void
    {
        $this
            ->setName('inpost:mapping:suggest')
            ->setDescription('Show category (and attribute) mapping suggestions')
            ->addOption('attributes', 'a', InputOption::VALUE_NONE, 'Suggest sources for attributes of mapped categories')
            ->addOption('search', 's', InputOption::VALUE_REQUIRED, 'Fuzzy-search InPost leaf categories');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        if ($phrase = (string) $input->getOption('search')) {
            $rows = [];
            foreach ($this->categoryAssistant->search($phrase) as $s) {
                $rows[] = [$s['score'], $s['path']];
            }
            $io->table(['Score', 'InPost category'], $rows);

            return 0;
        }

        if ($input->getOption('attributes')) {
            $rows = [];
            foreach ($this->attributeAssistant->getOverview()['attributes'] as $attr) {
                $s = $attr['suggestions'][0] ?? null;
                $rows[] = [
                    $attr['name'],
                    $attr['categories_count'] . ($attr['required_count'] ? ' (req ' . $attr['required_count'] . ')' : ''),
                    $attr['current'] ? ($attr['current']['mixed'] ? 'mixed' : $attr['current']['type'] . ': ' . $attr['current']['name']) . ($attr['current']['partial'] ? ' (partial)' : '') : '-',
                    $s ? $s['label'] . ' [' . $s['score'] . ']' : '-',
                    $s ? $s['reason'] : '',
                ];
            }
            $io->table(['InPost attribute', 'Categories', 'Current', 'Suggestion', 'Why'], $rows);
            $io->note(sprintf('%.2fs', microtime(true) - $started));

            return 0;
        }

        $overview = $this->categoryAssistant->getOverview();
        $rows = [];
        foreach ($overview['categories'] as $cat) {
            $label = str_repeat('  ', $cat['depth']) . $cat['name'] . ' (' . $cat['products'] . ')';
            $suggestions = array_map(
                static fn (array $s): string => '[' . $s['score'] . '] ' . $s['path'],
                $cat['suggestions']
            );
            $rows[] = [$label, $cat['current']['path'] ?? '', implode("\n", $suggestions) ?: '-'];
        }
        $io->table(['PrestaShop category', 'Mapped to', 'Suggestions'], $rows);
        $io->listing(array_map(
            static fn (string $k, int $v): string => $k . ': ' . $v,
            array_keys($overview['summary']),
            $overview['summary']
        ));
        $io->note(sprintf('%.2fs', microtime(true) - $started));

        return 0;
    }
}
