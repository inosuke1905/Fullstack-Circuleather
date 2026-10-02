/*
 * Closes the native account <details> menu when the user clicks outside it.
 * The browser handles opening and keyboard interaction; this script adds outside-click dismissal.
 */

const accountMenu = document.querySelector('.account-menu');

if (accountMenu) {
    document.addEventListener('click', (event) => {
        if (!accountMenu.contains(event.target)) {
            accountMenu.open = false;
        }
    });
}
