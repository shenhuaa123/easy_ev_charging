<?php

declare(strict_types=1);

use App\Core\View;

$totalPages = isset($totalPages) ? max(1, (int)$totalPages) : 1;
$currentPage = isset($currentPage) ? max(1, min((int)$currentPage, $totalPages)) : 1;

$paginationPath = isset($paginationPath) ? (string)$paginationPath : 'index.php';
$paginationAriaLabel = isset($paginationAriaLabel)
    ? (string)$paginationAriaLabel
    : '列表分页';
$paginationTotal = isset($paginationTotal) ? max(0, (int)$paginationTotal) : 0;
$paginationUnit = isset($paginationUnit) ? (string)$paginationUnit : '条记录';
$queryParameters = isset($queryParameters) && is_array($queryParameters)
    ? $queryParameters
    : [];

?>

<?php if($totalPages > 1): ?>
    <?php
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    ?>

    <nav class="pagination" aria-label="<?= View::escape($paginationAriaLabel) ?>">
        <?php if($currentPage > 1): ?>
            <a
                class="pagination-link"
                href="<?= View::escape(
                    View::buildPageUrl(
                        $paginationPath,
                        $currentPage - 1,
                        $queryParameters
                    )
                ) ?>"
            >
                上一页
            </a>
        <?php else: ?>
            <span class="pagination-disabled">上一页</span>
        <?php endif; ?>

        <?php for($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
            <?php if($pageNumber === $currentPage): ?>
                <span class="pagination-current">
                    <?= View::escape($pageNumber) ?>
                </span>
            <?php else: ?>
                <a
                    class="pagination-link"
                    href="<?= View::escape(
                        View::buildPageUrl(
                            $paginationPath,
                            $pageNumber,
                            $queryParameters
                        )
                    ) ?>"
                >
                    <?= View::escape($pageNumber) ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if($currentPage < $totalPages): ?>
            <a
                class="pagination-link"
                href="<?= View::escape(
                    View::buildPageUrl(
                        $paginationPath,
                        $currentPage + 1,
                        $queryParameters
                    )
                ) ?>"
            >
                下一页
            </a>
        <?php else: ?>
            <span class="pagination-disabled">下一页</span>
        <?php endif; ?>

        <div class="pagination-summary">
            共 <?= View::escape($paginationTotal) ?>
            <?= View::escape($paginationUnit) ?>，
            第 <?= View::escape($currentPage) ?>
            / <?= View::escape($totalPages) ?> 页
        </div>
    </nav>
<?php endif; ?>