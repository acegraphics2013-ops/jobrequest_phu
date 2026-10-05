document.addEventListener('click', function(e){
    var t = e.target;
    while (t && t.tagName !== 'A') t = t.parentElement;
    if (!t) return;
    var href = t.getAttribute('href');
    if (!href) return;
    if (href.indexOf('/logout.php') !== -1 || href.indexOf('logout.php') !== -1) {
        var ok = confirm('Are you sure you want to log out? Click OK for Yes, Cancel for No.');
        if (!ok) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    }
});
