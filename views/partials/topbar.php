<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

$topbarSystemName = isset($topbarSystemName) ? (string)$topbarSystemName : '易充充电管理系统';
$topbarIdentityLabel = isset($topbarIdentityLabel) ? (string)$topbarIdentityLabel : '';
$topbarDisplayName = isset($topbarDisplayName) ? (string)$topbarDisplayName : '';
$topbarLinks = isset($topbarLinks) && is_array($topbarLinks) ? $topbarLinks : [];
$topbarTheme = isset($topbarTheme) ? (string)$topbarTheme : 'admin';
$topbarThemeClass = $topbarTheme === 'user' ? 'topbar-user' : 'topbar-admin';
$topbarCsrf = null;

?>

<header class="topbar <?= View::escape($topbarThemeClass) ?>">
    <h1 class="system-name"><?= View::escape($topbarSystemName) ?></h1>

    <div class="topbar-actions">
        <?php if($topbarIdentityLabel !== '' || $topbarDisplayName !== ''): ?>
            <span>
                <?= View::escape($topbarIdentityLabel) ?>：
                <?= View::escape($topbarDisplayName) ?>
            </span>
        <?php endif; ?>

        <?php foreach($topbarLinks as $topbarLink): ?>
            <?php
            if(!is_array($topbarLink)){
                continue;
            }

            $linkLabel = (string)($topbarLink['label'] ?? '');
            $linkHref = (string)($topbarLink['href'] ?? '');
            $linkMethod = strtolower((string)($topbarLink['method'] ?? 'get'));

            if($linkLabel === '' || $linkHref === ''){
                continue;
            }
            ?>

            <?php if($linkMethod === 'post'): ?>
                <?php
                if($topbarCsrf === null){
                    $topbarCsrf = new Csrf(new Session());
                }

                $topbarCsrfToken = $topbarCsrf->token();
                ?>

                <form class="topbar-form" method="post" action="<?= View::escape($linkHref) ?>">
                    <input
                        type="hidden"
                        name="<?= View::escape(Csrf::FIELD_NAME) ?>"
                        value="<?= View::escape($topbarCsrfToken) ?>"
                    >

                    <button class="topbar-link topbar-button" type="submit">
                        <?= View::escape($linkLabel) ?>
                    </button>
                </form>
            <?php else: ?>
                <a class="topbar-link" href="<?= View::escape($linkHref) ?>">
                    <?= View::escape($linkLabel) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</header>