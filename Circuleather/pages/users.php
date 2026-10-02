<?php
if (!defined('CIRCULEATHER_APP') || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit;
}

$accounts = [];
$accountsLoadFailed = false;
try {
    require_once __DIR__ . '/../db.php';
    $accounts = $mysqli->query(
        'SELECT id, full_name, email, role, is_active, created_at FROM users ORDER BY full_name, id'
    )->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $exception) {
    error_log('Could not load user accounts: ' . $exception->getMessage());
    $accountsLoadFailed = true;
}
?>
<section class="page-section users-page" aria-labelledby="users-title">
    <div class="page-heading">
        <div><p class="eyebrow"><?= t('Toegangsbeheer', 'Access management') ?></p><h1 id="users-title"><?= t('Gebruikers', 'User accounts') ?></h1></div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <p class="settings-saved" role="status"><?= t('Account aangemaakt.', 'Account created.') ?></p>
    <?php elseif (isset($_GET['saved'])): ?>
        <p class="settings-saved" role="status"><?= t('Account bijgewerkt.', 'Account updated.') ?></p>
    <?php elseif (isset($_GET['password_saved'])): ?>
        <p class="settings-saved" role="status"><?= t('Wachtwoord opnieuw ingesteld.', 'Password reset successfully.') ?></p>
    <?php elseif (isset($_GET['password_invalid'])): ?>
        <p class="form-error" role="alert"><?= t('Gebruik een wachtwoord van 10 tot 72 tekens.', 'Use a password between 10 and 72 characters.') ?></p>
    <?php elseif (isset($_GET['deleted'])): ?>
        <p class="settings-saved" role="status"><?= t('Account verwijderd.', 'Account deleted.') ?></p>
    <?php elseif (isset($_GET['error'])): ?>
        <p class="form-error" role="alert"><?= t('Account kon niet worden opgeslagen. Probeer opnieuw.', 'The account could not be saved. Please try again.') ?></p>
    <?php elseif (isset($_GET['duplicate'])): ?>
        <p class="form-error" role="alert"><?= t('Dit e-mailadres is al in gebruik.', 'That email address is already in use.') ?></p>
    <?php elseif (isset($_GET['invalid'])): ?>
        <p class="form-error" role="alert"><?= t('Controleer de ingevoerde accountgegevens.', 'Check the account details and try again.') ?></p>
    <?php elseif (isset($_GET['self'])): ?>
        <p class="form-error" role="alert"><?= t('Je kunt je eigen rol, toegang of account niet wijzigen.', 'You cannot change your own role, access, or account.') ?></p>
    <?php elseif (isset($_GET['last_admin'])): ?>
        <p class="form-error" role="alert"><?= t('Er moet minimaal één actieve beheerder overblijven.', 'At least one active administrator must remain.') ?></p>
    <?php elseif (isset($_GET['not_found'])): ?>
        <p class="form-error" role="alert"><?= t('Dit account bestaat niet meer.', 'That account no longer exists.') ?></p>
    <?php endif; ?>

    <?php if ($accountsLoadFailed): ?>
        <p class="form-error" role="alert"><?= t('Gebruikers konden niet worden geladen.', 'Could not load user accounts.') ?></p>
    <?php endif; ?>

    <section class="settings-panel user-create-panel" aria-labelledby="create-user-title">
        <div class="settings-panel-heading">
            <span class="settings-icon" aria-hidden="true">+</span>
            <div><h2 id="create-user-title"><?= t('Account toevoegen', 'Add an account') ?></h2><p><?= t('Nieuwe accounts beginnen met toegang tot batches en orders.', 'New accounts start with access to batches and orders.') ?></p></div>
        </div>
        <form class="user-create-form" action="?page=users" method="post">
            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">
            <label><?= t('Naam', 'Name') ?><input name="full_name" maxlength="120" required autocomplete="name"></label>
            <label><?= t('E-mailadres', 'Email address') ?><input name="email" type="email" maxlength="254" required autocomplete="email"></label>
            <label><?= t('Tijdelijk wachtwoord', 'Temporary password') ?><input name="password" type="password" minlength="10" maxlength="72" required autocomplete="new-password"></label>
            <label><?= t('Rol', 'Role') ?><select name="role"><option value="worker"><?= t('Medewerker', 'Worker') ?></option><option value="admin"><?= t('Beheerder', 'Administrator') ?></option></select></label>
            <button class="button button-primary" type="submit"><?= t('Account aanmaken', 'Create account') ?></button>
        </form>
    </section>

    <div class="user-list-heading"><h2><?= t('Bestaande accounts', 'Existing accounts') ?></h2><span><?= count($accounts) ?></span></div>
    <div class="table-wrap user-table-wrap">
        <table class="user-table">
            <thead><tr><th><?= t('Naam', 'Name') ?></th><th><?= t('E-mailadres', 'Email address') ?></th><th><?= t('Rol', 'Role') ?></th><th><?= t('Toegang', 'Access') ?></th><th><?= t('Wachtwoord opnieuw instellen', 'Reset password') ?></th><th><?= t('Acties', 'Actions') ?></th></tr></thead>
            <tbody>
                <?php if ($accounts === []): ?>
                    <tr><td colspan="6"><?= t('Geen accounts gevonden.', 'No accounts found.') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($accounts as $account): ?>
                    <?php $formId = 'user-form-' . (int) $account['id']; $isCurrentUser = (int) $account['id'] === (int) $_SESSION['user_id']; ?>
                    <tr>
                        <td>
                            <form id="<?= escape($formId) ?>" action="?page=users" method="post">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                            </form>
                            <label class="visually-hidden" for="user-name-<?= (int) $account['id'] ?>"><?= t('Naam', 'Name') ?></label>
                            <input form="<?= escape($formId) ?>" id="user-name-<?= (int) $account['id'] ?>" name="full_name" value="<?= escape($account['full_name']) ?>" maxlength="120" required>
                        </td>
                        <td><label class="visually-hidden" for="user-email-<?= (int) $account['id'] ?>"><?= t('E-mailadres', 'Email address') ?></label><input form="<?= escape($formId) ?>" id="user-email-<?= (int) $account['id'] ?>" name="email" type="email" value="<?= escape($account['email']) ?>" maxlength="254" required></td>
                        <td><label class="visually-hidden" for="user-role-<?= (int) $account['id'] ?>"><?= t('Rol', 'Role') ?></label><select form="<?= escape($formId) ?>" id="user-role-<?= (int) $account['id'] ?>" name="role"<?= $isCurrentUser ? ' disabled' : '' ?>><option value="worker"<?= $account['role'] === 'worker' ? ' selected' : '' ?>><?= t('Medewerker', 'Worker') ?></option><option value="admin"<?= $account['role'] === 'admin' ? ' selected' : '' ?>><?= t('Beheerder', 'Administrator') ?></option></select></td>
                        <td><label class="visually-hidden" for="user-active-<?= (int) $account['id'] ?>"><?= t('Toegang', 'Access') ?></label><select form="<?= escape($formId) ?>" id="user-active-<?= (int) $account['id'] ?>" name="is_active"<?= $isCurrentUser ? ' disabled' : '' ?>><option value="1"<?= (bool) $account['is_active'] ? ' selected' : '' ?>><?= t('Actief', 'Active') ?></option><option value="0"<?= !(bool) $account['is_active'] ? ' selected' : '' ?>><?= t('Geblokkeerd', 'Disabled') ?></option></select></td>
                        <td>
                            <form class="user-reset-form" action="?page=users" method="post">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                <label class="visually-hidden" for="user-password-<?= (int) $account['id'] ?>"><?= t('Nieuw wachtwoord', 'New password') ?></label>
                                <input id="user-password-<?= (int) $account['id'] ?>" name="new_password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                                <button class="button button-secondary" type="submit"><?= t('Reset', 'Reset') ?></button>
                            </form>
                        </td>
                        <td class="user-actions"><button class="button button-secondary" form="<?= escape($formId) ?>" type="submit"><?= t('Opslaan', 'Save') ?></button><?php if (!$isCurrentUser): ?><button class="button button-danger" form="<?= escape($formId) ?>" type="submit" name="delete" value="1" formnovalidate><?= t('Verwijderen', 'Delete') ?></button><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>