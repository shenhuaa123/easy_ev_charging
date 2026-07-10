<?php

declare(strict_types=1);

use App\Core\View;

$hasActiveFilters = isset($hasActiveFilters) && (bool)$hasActiveFilters;
$emptyMessage = isset($emptyMessage) ? (string)$emptyMessage : '当前还没有数据。';
$filteredEmptyMessage = isset($filteredEmptyMessage) ? (string)$filteredEmptyMessage : '当前筛选条件下没有符合要求的数据。';
$resetUrl = isset($resetUrl) ? (string)$resetUrl : 'index.php';
$resetLabel = isset($resetLabel) ? (string)$resetLabel : '重置筛选';

?>

<div class="empty-state">
    <?php if($hasActiveFilters): ?>
        <?= View::escape($filteredEmptyMessage) ?>

        <br><br>

        <a class="reset-button" href="<?= View::escape($resetUrl) ?>">
            <?= View::escape($resetLabel) ?>
        </a>
    <?php else: ?>
        <?= View::escape($emptyMessage) ?>
    <?php endif; ?>
</div>