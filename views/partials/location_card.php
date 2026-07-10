<?php

declare(strict_types=1);

use App\Core\View;
use App\Models\Location;

$locationCardLocation = isset($locationCardLocation)
    && $locationCardLocation instanceof Location
        ? $locationCardLocation
        : null;

$locationCardId = isset($locationCardId)
    ? (int)$locationCardId
    : 0;

$locationCardAvailableCount = isset($locationCardAvailableCount)
    ? max(0, (int)$locationCardAvailableCount)
    : 0;

$locationCardButtonLabel = isset($locationCardButtonLabel)
    ? (string)$locationCardButtonLabel
    : '查看站点与充电桩';

$locationCardRatingSummary = isset($locationCardRatingSummary)
    && is_array($locationCardRatingSummary)
        ? $locationCardRatingSummary
        : [
            'review_count' => 0,
            'average_rating' => 0.0,
        ];

$locationCardReviewCount = (int)($locationCardRatingSummary['review_count'] ?? 0);
$locationCardAverageRating = (float)($locationCardRatingSummary['average_rating'] ?? 0);
$locationCardRoundedRating = (int)round($locationCardAverageRating);
$locationCardRoundedRating = max(0, min(5, $locationCardRoundedRating));

if($locationCardLocation === null || $locationCardId <= 0){
    return;
}

?>

<article class="location-card">
    <h3 class="location-name">
        <?= View::escape($locationCardLocation->getLocationName()) ?>
    </h3>

    <p class="location-code">
        站点编号：<?= View::escape($locationCardLocation->getLocationCode()) ?>
    </p>

    <div class="location-info">
        <p>
            <strong>完整地址：</strong>
            <?= View::escape($locationCardLocation->getFullAddress()) ?>
        </p>

        <p>
            <strong>当前状态：</strong>
            <?= View::escape($locationCardLocation->getStatusLabel()) ?>
        </p>

        <p>
            <strong>当前可用充电桩：</strong>

            <span class="<?= $locationCardAvailableCount > 0
                ? 'available-count'
                : 'unavailable-count' ?>">
                <?= View::escape($locationCardAvailableCount) ?> 台
            </span>
        </p>

        <p class="loc-rating">
            <strong>站点评分：</strong>

            <?php if($locationCardReviewCount > 0): ?>
                <span class="rating-stars">
                    <?= View::escape(str_repeat('★', $locationCardRoundedRating)) ?>
                    <?= View::escape(str_repeat('☆', 5 - $locationCardRoundedRating)) ?>
                </span>

                <span class="rating-text">
                    <?= View::escape(number_format($locationCardAverageRating, 2)) ?>
                    （<?= View::escape((string)$locationCardReviewCount) ?>条评价）
                </span>
            <?php else: ?>
                <span class="rating-empty">
                    暂无评价
                </span>
            <?php endif; ?>
        </p>

        <?php if($locationCardLocation->getDescription() !== null): ?>
            <p>
                <strong>站点说明：</strong>
                <?= View::escape($locationCardLocation->getDescription()) ?>
            </p>
        <?php endif; ?>
    </div>

    <a
        class="detail-button"
        href="detail.php?id=<?= View::escape($locationCardId) ?>"
    >
        <?= View::escape($locationCardButtonLabel) ?>
    </a>
</article>