(function ($) {
    'use strict';

    // Auto-submit the section form when a frame radio is double-clicked
    $(document).on('dblclick', '.ftea-frames-table input[type="radio"]', function () {
        $(this).closest('form').submit();
    });

    // Confirm before importing all sections
    $(document).on('submit', 'form[action*="ftea_import_all"]', function () {
        return confirm('Import all remaining sections now?');
    });

}(jQuery));
