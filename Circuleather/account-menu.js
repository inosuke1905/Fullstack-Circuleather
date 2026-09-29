const accountMenu = document.querySelector('.account-menu');

if (accountMenu) {
    document.addEventListener('click', (event) => {
        if (!accountMenu.contains(event.target)) {
            accountMenu.open = false;
        }
    });
}
