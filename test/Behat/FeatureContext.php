<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Behat;

use Behat\Behat\Context\Context;

/**
 * Behat context for the xphp language-server acceptance suite.
 *
 * The shared in-memory world (workspace, handler stack, fixture Givens, helpers)
 * lives in {@see WorldTrait}; the When/Then steps are split by theme into one
 * trait each. Every handler is driven against a fully in-memory workspace, so
 * scenarios are isolated and parallel-safe.
 */
final class FeatureContext implements Context
{
    use WorldTrait;
    use NavigateSteps;
    use EditSteps;
    use UnderstandSteps;
    use ValidateSteps;
    use FindSteps;
}
