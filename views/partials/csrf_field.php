<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

if(
    !isset($csrfToken)
    || !is_string($csrfToken)
    || $csrfToken === ''
){
    throw new \RangeException('CSRF字段组件缺少有效令牌。');
}

?>

<input
    type="hidden"
    name="<?= View::escape(Csrf::FIELD_NAME) ?>"
    value="<?= View::escape($csrfToken) ?>"
>