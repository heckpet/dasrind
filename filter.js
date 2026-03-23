document.addEventListener('DOMContentLoaded', function () {

    // Karten sind <article> mit data-kategorie, aber wir müssen das <li> ausblenden
    var cards      = document.querySelectorAll('.programm__grid [data-kategorie]');
    var kategorien = [];

    cards.forEach(function (card) {
        var kat = card.getAttribute('data-kategorie');
        if ( kat && kategorien.indexOf(kat) === -1 ) {
            kategorien.push(kat);
        }
    });

    // Filter-Buttons vor der Section einfügen
    var ziel = document.querySelector('.programm__grid');
    if ( ! ziel ) return;

    var filterDiv = document.createElement('div');
    filterDiv.className = 'event-filter-buttons';

    var alleBtn = document.createElement('button');
    alleBtn.textContent = 'Alle';
    alleBtn.className   = 'event-filter-btn aktiv';
    alleBtn.setAttribute('data-filter', 'alle');
    filterDiv.appendChild(alleBtn);

    kategorien.sort().forEach(function (kat) {
        var btn = document.createElement('button');
        btn.textContent = kat;
        btn.className   = 'event-filter-btn';
        btn.setAttribute('data-filter', kat);
        filterDiv.appendChild(btn);
    });

    ziel.parentNode.insertBefore( filterDiv, ziel );

    // Klick-Handler – blendet das <li> aus, nicht das <article>
    filterDiv.addEventListener('click', function (e) {
        if ( ! e.target.classList.contains('event-filter-btn') ) return;

        filterDiv.querySelectorAll('.event-filter-btn').forEach(function (b) {
            b.classList.remove('aktiv');
        });
        e.target.classList.add('aktiv');

        var filter = e.target.getAttribute('data-filter');

        cards.forEach(function (card) {
            // card ist das <article>, dessen Elternelement ist das <li>
            var li = card.closest('li');
            if ( ! li ) return;

            if ( filter === 'alle' || card.getAttribute('data-kategorie') === filter ) {
                li.style.display = '';
            } else {
                li.style.display = 'none';
            }
        });
    });
});
