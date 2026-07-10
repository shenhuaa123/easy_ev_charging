<?php

declare(strict_types=1);

use App\Core\View;

$flashMessages = isset($flashMessages) && is_array($flashMessages)
    ? $flashMessages
    : [];

?>

<?php foreach($flashMessages as $flashMessage): ?>
    <?php
    $messageType = (string)($flashMessage['type'] ?? 'info');
    $message = (string)($flashMessage['message'] ?? '');
    ?>

    <div class="message <?= View::escape(View::flashMessageClass($messageType)) ?>">
        <?= View::escape($message) ?>
    </div>
<?php endforeach; ?>