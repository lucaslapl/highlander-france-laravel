document.addEventListener('DOMContentLoaded', () => {
    const textarea = document.getElementById('markdownEditor');
    if (!textarea || typeof EasyMDE === 'undefined') {
        return;
    }

    const insert = (before, after = '') => {
        const mde = textarea._mde;
        if (!mde) {
            return;
        }
        const cm = mde.codemirror;
        const selection = cm.getSelection();
        cm.replaceSelection(before + (selection || 'texte') + after);
        cm.focus();
    };

    const block = (type) => {
        insert(`:::${type} Titre\n`, '\n:::');
    };

    const easyMDE = new EasyMDE({
        element: textarea,
        spellChecker: false,
        status: ['lines', 'words'],
        toolbar: [
            'bold', 'italic', 'heading', '|',
            'quote', 'unordered-list', 'ordered-list', 'table', 'link', '|',
            {
                name: 'info',
                action: () => block('info'),
                className: 'fa fa-info-circle',
                title: 'Bloc info (bleu)',
            },
            {
                name: 'conseil',
                action: () => block('conseil'),
                className: 'fa fa-lightbulb',
                title: 'Bloc conseil (vert)',
            },
            {
                name: 'danger',
                action: () => block('danger'),
                className: 'fa fa-triangle-exclamation',
                title: 'Bloc danger (rouge)',
            },
            {
                name: 'combo',
                action: () => block('combo'),
                className: 'fa fa-users',
                title: 'Bloc combo (bleu équipe)',
            },
            {
                name: 'flank',
                action: () => block('flank'),
                className: 'fa fa-bolt',
                title: 'Bloc flank (rouge équipe)',
            },
            {
                name: 'couleur',
                action: () => insert('<span class="hl-blue">', '</span>'),
                className: 'fa fa-palette',
                title: 'Couleur (hl-blue, hl-red, hl-green, hl-gold)',
            },
            '|', 'preview', 'guide',
        ],
    });
    textarea._mde = easyMDE;

    textarea.form?.addEventListener('submit', (e) => {
        if (easyMDE.value().trim() === '') {
            e.preventDefault();
            alert('Le champ "Réponse Markdown" est obligatoire.');
            easyMDE.codemirror.focus();
        }
    });
});
