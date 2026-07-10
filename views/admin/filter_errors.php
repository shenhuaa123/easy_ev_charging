<?php

declare(strict_types=1);

use App\Core\View;

$filterErrors = isset($filterErrors) && is_array($filterErrors) ? $filterErrors : [];

?>

<?php if($filterErrors !== []): ?>
    <ul class="filter-error-list">
        <?php foreach($filterErrors as $filterError): ?>
            <li><?= View::escape((string)$filterError) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>