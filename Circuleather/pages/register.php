<?php
if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}
?>
<section class="auth-page" aria-labelledby="register-title">
    <p class="eyebrow"><?= t('Aan de slag', 'Get started') ?></p>
    <h1 id="register-title"><?= t('Account aanmaken', 'Create your account') ?></h1>
    <p class="auth-intro"><?= t('Maak een account aan om Circuleather te gebruiken.', 'Create an account to use Circuleather.') ?></p>

    <?php if ($authError !== null): ?>
        <p class="form-error" role="alert"><?= $authError ?></p>
    <?php endif; ?>

    <form class="auth-form" action="?page=register" method="post">
        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
        <label for="register-name"><?= t('Volledige naam', 'Full name') ?></label>
        <input id="register-name" name="full_name" type="text" maxlength="120" autocomplete="name" required value="<?= escape($fullName ?? '') ?>">
        <label for="register-email"><?= t('E-mailadres', 'Email address') ?></label>
        <input id="register-email" name="email" type="email" maxlength="254" autocomplete="email" required value="<?= escape($email ?? '') ?>">
        <label for="register-password"><?= t('Wachtwoord (minimaal 10 tekens)', 'Password (at least 10 characters)') ?></label>
        <input id="register-password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
        <label for="confirm-password"><?= t('Bevestig wachtwoord', 'Confirm password') ?></label>
        <input id="confirm-password" name="confirm_password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
        <button class="button button-primary" type="submit"><?= t('Account aanmaken', 'Create account') ?></button>
    </form>

    <p class="auth-switch"><?= t('Heb je al een account?', 'Already have an account?') ?> <a href="?page=login"><?= t('Inloggen', 'Log in') ?></a></p>
</section>
