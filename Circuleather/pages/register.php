<?php
if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}
?>
<section class="auth-page" aria-labelledby="register-title">
    <p class="eyebrow"><?= t('Toegang beperkt', 'Access restricted') ?></p>
    <h1 id="register-title"><?= t('Account aanmaken is uitgeschakeld', 'Account creation is disabled') ?></h1>
    <p class="auth-intro"><?= t('Nieuwe accounts worden door de beheerder aangemaakt. Neem contact op met je werkgever om toegang te krijgen.', 'New accounts are created by the administrator. Contact your employer to request access.') ?></p>
    <p class="auth-switch"><a href="?page=login"><?= t('Inloggen', 'Log in') ?></a></p>
</section>
