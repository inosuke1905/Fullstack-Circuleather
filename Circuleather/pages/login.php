<?php
if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}
?>
<section class="auth-page" aria-labelledby="login-title">
    <p class="eyebrow"><?= t('Welkom terug', 'Welcome back') ?></p>
    <h1 id="login-title"><?= t('Inloggen', 'Log in') ?></h1>
    <p class="auth-intro"><?= t('Log in om verder te gaan naar je voorraad. Accounts worden door de beheerder aangelegd.', 'Sign in to continue to your inventory. Accounts are created by the administrator.') ?></p>

    <?php if ($authError !== null): ?>
        <p class="form-error" role="alert"><?= $authError ?></p>
    <?php elseif (isset($_GET['logged_out'])): ?>
        <p class="settings-saved" role="status"><?= t('Je bent uitgelogd.', 'You have been logged out.') ?></p>
    <?php endif; ?>

    <form class="auth-form" action="?page=login" method="post">
        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
        <label for="login-email"><?= t('E-mailadres', 'Email address') ?></label>
        <input id="login-email" name="email" type="email" autocomplete="email" required value="<?= escape($email ?? '') ?>">
        <label for="login-password"><?= t('Wachtwoord', 'Password') ?></label>
        <input id="login-password" name="password" type="password" autocomplete="current-password" required>
        <button class="button button-primary" type="submit"><?= t('Inloggen', 'Log in') ?></button>
    </form>
</section>
