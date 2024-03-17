<?php

declare(strict_types=1);

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\ControlStructure;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerConfiguration\AllowedValueSubset;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\FixerDefinition\VersionSpecification;
use PhpCsFixer\FixerDefinition\VersionSpecificCodeSample;
use PhpCsFixer\Tokenizer\Analyzer\Analysis\SwitchAnalysis;
use PhpCsFixer\Tokenizer\Analyzer\ControlCaseStructuresAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * @author Vincent Langlet
 */
final class SingleExpressionPerLineFixer extends AbstractFixer implements ConfigurableFixerInterface, WhitespacesAwareFixerInterface
{
    /**
     * @internal
     */
    public const ELEMENTS_ARRAYS = 'arrays';

    /**
     * @internal
     */
    public const ELEMENTS_ARGUMENTS = 'arguments';

    /**
     * @internal
     */
    public const ELEMENTS_PARAMETERS = 'parameters';

    /**
     * @internal
     */
    public const ELEMENTS_CONTROL_STRUCTURES = 'control_structures';

    /**
     * @internal
     */
    public const SWITCH_CASES = 'case';

    /**
     * @internal
     */
    public const MATCH_EXPRESSIONS = 'match';

    private ?array $switchColonIndexes = null;

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Multi-line arrays, arguments list, parameters list, control structures, `switch` cases and `match` expressions should have one element by line.',
            [
                new CodeSample("<?php\narray(1,\n    2);\n"),
                new CodeSample("<?php\nfoo(1,\n    2);\n", ['elements' => [self::ELEMENTS_ARGUMENTS]]),
                new CodeSample("<?php\nif (\$a\n    && \$b) {};\n", ['elements' => [self::ELEMENTS_CONTROL_STRUCTURES]]),
                new CodeSample("<?php\nswitch (\$foo) {\n    case 0: case 1:\n        return null;\n    };\n", ['elements' => [self::SWITCH_CASES]]),
                new CodeSample("<?php\nfunction foo(\$x,\n    \$y)\n{\n}\n", ['elements' => [self::ELEMENTS_PARAMETERS]]),
                new VersionSpecificCodeSample("<?php\nmatch(\$x) {\n    1 => 1, 2 => 2\n};\n", new VersionSpecification(8_00_00), ['elements' => [self::MATCH_EXPRESSIONS]]),
            ]
        );
    }

    /**
     * {@inheritdoc}
     *
     * Must run before ArrayIndentationFixer, StatementIndentationFixer.
     * Must run after MethodArgumentSpaceFixer.
     */
    public function getPriority(): int
    {
        return 29;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([T_ARRAY, CT::T_ARRAY_SQUARE_BRACE_OPEN, '(']);
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            (new FixerOptionBuilder('elements', 'Which expression must have one element by line.'))
                ->setAllowedTypes(['array'])
                ->setAllowedValues([new AllowedValueSubset([
                    self::ELEMENTS_ARRAYS,
                    self::ELEMENTS_ARGUMENTS,
                    self::ELEMENTS_PARAMETERS,
                    self::ELEMENTS_CONTROL_STRUCTURES,
                    self::SWITCH_CASES,
                    self::MATCH_EXPRESSIONS,
                ])])
                ->setDefault([
                    self::ELEMENTS_ARRAYS,
                    self::ELEMENTS_ARGUMENTS,
                    self::ELEMENTS_PARAMETERS,
                    self::ELEMENTS_CONTROL_STRUCTURES,
                    self::SWITCH_CASES,
                    self::MATCH_EXPRESSIONS,
                ])
                ->getOption(),
        ]);
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $this->processBlock($tokens, 0, $tokens->count() - 1, false);
    }

    /**
     * Process tokens from $tokens[$begin] to $tokens[$end] and add missing new lines:
     * - If the code is not multiline, there is nothing to add
     * - If the code is multiline, it looks for newline right after the first token,
     * after every comma, and right before the last token and add them when they are missing.
     *
     * Since recursion is used to handle things like array inside array or functions we need
     * a parameter $shouldAddNewLine to knows if we are currently fixing the structure, which
     * requires to add newlines, or if we are just looking for the next one to fix.
     *
     * @param null|array<string> $lineDelimiters
     * @param null|array<int>    $lineDelimitersIndexes
     */
    private function processBlock(
        Tokens $tokens,
        int $begin,
        int $end,
        bool $shouldAddNewLine,
        ?array $lineDelimiters = null,
        ?array $lineDelimitersIndexes = null
    ): int {
        $tokenAddedCount = 0;
        if (!$tokens->isPartialCodeMultiline($begin, $end)) {
            return $tokenAddedCount;
        }

        if ($shouldAddNewLine) {
            $tokenAddedCount += $this->addNewLineAfterIfNecessary($tokens, $begin, 1);
        }

        $fixArrays = \in_array(self::ELEMENTS_ARRAYS, $this->configuration['elements'], true);
        $fixArguments = \in_array(self::ELEMENTS_ARGUMENTS, $this->configuration['elements'], true);
        $fixParameters = \in_array(self::ELEMENTS_PARAMETERS, $this->configuration['elements'], true);
        $fixControlStructures = \in_array(self::ELEMENTS_CONTROL_STRUCTURES, $this->configuration['elements'], true);
        $fixSwitchCases = \in_array(self::SWITCH_CASES, $this->configuration['elements'], true);
        $fixMatch = \in_array(self::MATCH_EXPRESSIONS, $this->configuration['elements'], true);

        for ($index = $begin + 1; $index < $end + $tokenAddedCount; ++$index) {
            /** @var Token $token */
            $token = $tokens[$index];

            if (
                null !== $lineDelimiters
                && $token->equalsAny($lineDelimiters)
                && (null === $lineDelimitersIndexes || \in_array($index, $lineDelimitersIndexes, true))
            ) {
                if ($shouldAddNewLine) {
                    $tokenAddedCount += $this->addNewLineAfterIfNecessary($tokens, $index);
                }

                continue;
            }

            if ($tokens[$index]->isGivenKind(CT::T_ARRAY_SQUARE_BRACE_OPEN)) {
                $until = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_SQUARE_BRACE, $index);
                $added = $this->processBlock($tokens, $index, $until, $fixArrays, [',']);

                $index = $until + $added;
                $tokenAddedCount += $added;

                continue;
            }

            if (!$tokens[$index]->equals('(')) {
                continue;
            }

            $prevIndex = $tokens->getPrevMeaningfulToken($index);
            if ($tokens[$prevIndex]->isGivenKind(T_ARRAY)) {
                $until = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
                $added = $this->processBlock($tokens, $index, $until, $fixArrays, [',']);

                $index = $until + $added;
                $tokenAddedCount += $added;

                continue;
            }

            if ($tokens[$prevIndex]->isGivenKind([T_IF, T_ELSEIF, T_WHILE, T_FOREACH, T_CATCH])) {
                $until = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
                $added = $this->processBlock($tokens, $index, $until, $fixControlStructures);

                $index = $until + $added;
                $tokenAddedCount += $added;

                continue;
            }

            if ($tokens[$prevIndex]->isGivenKind(T_FOR)) {
                $until = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
                $added = $this->processBlock($tokens, $index, $until, $fixControlStructures, [';']);

                $index = $until + $added;
                $tokenAddedCount += $added;

                continue;
            }

            $prevPrevIndex = $tokens->getPrevMeaningfulToken($prevIndex);
            if ($tokens[$prevIndex]->equalsAny([']', [T_CLASS], [T_STRING], [T_VARIABLE], [T_STATIC]])
                && !$tokens[$prevPrevIndex]->isGivenKind(T_FUNCTION)
            ) {
                $until = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
                $added = $this->processBlock($tokens, $index, $until, $fixArguments, [',']);

                $index = $until + $added;
                $tokenAddedCount += $added;

                continue;
            }

            if (
                $tokens[$prevIndex]->isGivenKind([T_FN, T_FUNCTION])
                || $tokens[$prevIndex]->isGivenKind(T_STRING) && $tokens[$prevPrevIndex]->isGivenKind(T_FUNCTION)
            ) {
                $until = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
                $added = $this->processBlock($tokens, $index, $until, $fixParameters, [',']);

                $index = $until + $added;
                $tokenAddedCount += $added;

                continue;
            }

            if ($tokens[$prevIndex]->isGivenKind(T_SWITCH)) {
                $until = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
                $tokenAddedCount += $this->processBlock($tokens, $index, $until, $fixControlStructures);

                $index = $tokens->getNextTokenOfKind($index, ['{']);
                $until = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $index);
                $added = $this->processBlock(
                    $tokens,
                    $index,
                    $until,
                    $fixSwitchCases,
                    [':', ';'],
                    $this->getSwitchColonIndexes($tokens)
                );

                $index = $until + $added;
                $tokenAddedCount += $added;

                continue;
            }

            // @TODO: remove defined condition when PHP 8.0+ is required
            if (\defined('T_MATCH') && $tokens[$prevIndex]->isGivenKind(T_MATCH)) {
                $until = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
                $tokenAddedCount += $this->processBlock($tokens, $index, $until, $fixControlStructures);

                $index = $tokens->getNextTokenOfKind($index, ['{']);
                $until = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $index);
                $added = $this->processBlock($tokens, $index, $until, $fixMatch, [',']);

                $index = $until + $added;
                $tokenAddedCount += $added;
            }
        }

        if ($shouldAddNewLine) {
            $tokenAddedCount += $this->addNewLineBeforeIfNecessary($tokens, $end + $tokenAddedCount, -1);
        }

        return $tokenAddedCount;
    }

    /**
     * @return array<int>
     */
    private function getSwitchColonIndexes(Tokens $tokens): array
    {
        if (null === $this->switchColonIndexes) {
            $colonIndexes = [];

            /** @var SwitchAnalysis $analysis */
            foreach (ControlCaseStructuresAnalyzer::findControlStructures($tokens, [T_SWITCH]) as $analysis) {
                $default = $analysis->getDefaultAnalysis();

                if (null !== $default) {
                    $colonIndexes[] = $default->getColonIndex();
                }

                foreach ($analysis->getCases() as $caseAnalysis) {
                    $colonIndexes[] = $caseAnalysis->getColonIndex();
                }
            }

            $this->switchColonIndexes = $colonIndexes;
        }

        return $this->switchColonIndexes;
    }

    private function addNewLineAfterIfNecessary(Tokens $tokens, int $index, int $extraIndentation = 0): int
    {
        $next = $tokens->getNextMeaningfulToken($index);
        if ($tokens->isPartialCodeMultiline($index, $next - 1)) {
            return 0;
        }

        return $this->addNewLineAfter($tokens, $index, $extraIndentation);
    }

    private function addNewLineBeforeIfNecessary(Tokens $tokens, int $index, int $extraIndentation = 0): int
    {
        $previous = $tokens->getPrevMeaningfulToken($index);
        if ($tokens->isPartialCodeMultiline($previous, $index)) {
            return 0;
        }

        return $this->addNewLineAfter($tokens, $previous, $extraIndentation);
    }

    private function addNewLineAfter(Tokens $tokens, int $index, int $extraIndentation = 0): int
    {
        $indent = $this->getIndentation($tokens, $index, $extraIndentation);
        if ($tokens[$index + 1]->isWhitespace()) {
            $tokens[$index + 1] = new Token([T_WHITESPACE, "\n".$indent]);

            return 0;
        }

        $tokens->insertSlices([$index + 1 => new Token([T_WHITESPACE, "\n".$indent])]);

        return 1;
    }

    private function getIndentation(Tokens $tokens, int $index, int $extraIndentation = 0): string
    {
        // find out what the indentation is
        $searchIndex = $index;
        do {
            $prevWhitespaceTokenIndex = $tokens->getPrevTokenOfKind(
                $searchIndex,
                [[T_ENCAPSED_AND_WHITESPACE], [T_WHITESPACE]],
            );

            $searchIndex = $prevWhitespaceTokenIndex;
        } while (
            null !== $prevWhitespaceTokenIndex
            && !str_contains($tokens[$prevWhitespaceTokenIndex]->getContent(), "\n")
            && $prevWhitespaceTokenIndex > 1
        );

        if (null === $prevWhitespaceTokenIndex) {
            $existingIndentation = '';
        } elseif (!$tokens[$prevWhitespaceTokenIndex]->isGivenKind(T_WHITESPACE)) {
            return '';
        } else {
            $existingIndentation = $tokens[$prevWhitespaceTokenIndex]->getContent();
            $lastLineIndex = strrpos($existingIndentation, "\n");
            $existingIndentation = false === $lastLineIndex
                ? $existingIndentation
                : substr($existingIndentation, $lastLineIndex + 1);
        }

        if ($extraIndentation > 0) {
            return $existingIndentation.str_repeat($this->whitespacesConfig->getIndent(), $extraIndentation);
        }
        if ($extraIndentation < 0) {
            return substr($existingIndentation, 0, $extraIndentation * strlen($this->whitespacesConfig->getIndent()));
        }

        return $existingIndentation;
    }
}
