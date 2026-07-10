<?php

declare(strict_types=1);

use App\Core\View;

$pageTitle = isset($pageTitle) ? (string)$pageTitle : '';
$pageDescription = isset($pageDescription) ? (string)$pageDescription : '';
$pageBackLink = isset($pageBackLink) && is_array($pageBackLink) ? $pageBackLink : null;

?>

<section class="page-header">
    <div>
        <h2 class="page-title">
            <?= View::escape($pageTitle) ?>
        </h2>

        <?php if($pageDescription !== ''): ?>
            <p class="page-description">
                <?= View::escape($pageDescription) ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if($pageBackLink !== null): ?>
        <a
            class="<?= View::escape((string)($pageBackLink['class'] ?? 'secondary-button')) ?>"
            href="<?= View::escape((string)($pageBackLink['href'] ?? '#')) ?>"
        >
            <?= View::escape((string)($pageBackLink['label'] ?? '返回')) ?>
        </a>
    <?php endif; ?>
</section>