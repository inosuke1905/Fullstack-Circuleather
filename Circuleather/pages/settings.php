<?php
if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}
?>
<section class="page-section settings-page" aria-labelledby="settings-title">
    <div class="page-heading">
        <div><p class="eyebrow"><?= t('Voorkeuren', 'Preferences') ?></p><h1 id="settings-title"><?= t('Instellingen', 'Settings') ?></h1></div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <p class="settings-saved" role="status"><?= t('Instellingen opgeslagen.', 'Settings saved.') ?></p>
    <?php elseif (isset($_GET['error'])): ?>
        <p class="form-error" role="alert"><?= t('Instellingen konden niet worden opgeslagen. Probeer het opnieuw.', 'Settings could not be saved. Please try again.') ?></p>
    <?php endif; ?>

    <form class="settings-form" method="post" action="?page=settings">
        <div class="settings-grid">
            <section class="settings-panel" aria-labelledby="appearance-title">
                <div class="settings-panel-heading">
                    <span class="settings-icon" aria-hidden="true">Aa</span>
                    <div>
                        <h2 id="appearance-title"><?= t('Weergave', 'Appearance') ?></h2>
                        <p><?= t('Pas de interface aan jouw voorkeur aan.', 'Adjust the interface to your preference.') ?></p>
                    </div>
                </div>

                <div class="settings-fields">
                    <div class="settings-field">
                        <label for="language"><?= t('Taal', 'Language') ?></label>
                        <select id="language" name="language">
                            <option value="nl"<?= $language === 'nl' ? ' selected' : '' ?>>Nederlands</option>
                            <option value="en"<?= $language === 'en' ? ' selected' : '' ?>>English</option>
                        </select>
                    </div>
                    <div class="settings-field">
                        <label for="theme"><?= t('Thema', 'Theme') ?></label>
                        <select id="theme" name="theme">
                            <option value="light"<?= $settings['theme'] === 'light' ? ' selected' : '' ?>><?= t('Licht', 'Light') ?></option>
                            <option value="dark"<?= $settings['theme'] === 'dark' ? ' selected' : '' ?>><?= t('Donker', 'Dark') ?></option>
                            <option value="system"<?= $settings['theme'] === 'system' ? ' selected' : '' ?>><?= t('Systeemvoorkeur', 'System preference') ?></option>
                        </select>
                    </div>
                </div>
            </section>

            <section class="settings-panel" aria-labelledby="notifications-title">
                <div class="settings-panel-heading">
                    <span class="settings-icon" aria-hidden="true">!</span>
                    <div>
                        <h2 id="notifications-title"><?= t('Meldingen', 'Notifications') ?></h2>
                        <p><?= t('Kies welke voorraad- en ordermeldingen je wilt zien.', 'Choose which stock and order alerts to see.') ?></p>
                    </div>
                </div>

                <div class="settings-toggles">
                    <label class="settings-toggle" for="low-stock-notifications">
                        <span><strong><?= t('Lage voorraad', 'Low stock') ?></strong><small><?= t('Melding wanneer een batch onder minimum komt.', 'Alert when a batch falls below its minimum.') ?></small></span>
                        <input id="low-stock-notifications" type="checkbox" name="low_stock_notifications" value="1"<?= $settings['low_stock_notifications'] ? ' checked' : '' ?>>
                    </label>
                    <label class="settings-toggle" for="order-notifications">
                        <span><strong><?= t('Bestellingen', 'Orders') ?></strong><small><?= t('Melding bij een nieuwe of bijgewerkte bestelling.', 'Alert for new or updated orders.') ?></small></span>
                        <input id="order-notifications" type="checkbox" name="order_notifications" value="1"<?= $settings['order_notifications'] ? ' checked' : '' ?>>
                    </label>
                </div>
                <p class="settings-note"><?= t('Voorkeuren worden bij je account bewaard.', 'Preferences are saved to your account.') ?></p>
            </section>
        </div>

        <div class="settings-actions">
            <button class="button button-primary" type="submit"><?= t('Instellingen opslaan', 'Save settings') ?></button>
        </div>
    </form>
</section>
