"use strict";

(function ($) {
  $(document).ready(function () {
    // Option => the options it needs: an autofill needs its check, ignoring a
    // failed check needs at least one check.
    var requires = {
      '#woolab_icdic_ares_fill': '#woolab_icdic_ares_check',
      '#woolab_icdic_sk_fill': '#woolab_icdic_sk_check',
      '#woolab_icdic_ignore_check_fail': '#woolab_icdic_ares_check, #woolab_icdic_sk_check, #woolab_icdic_vies_check'
    };
    var vies_check = $('#woolab_icdic_vies_check');
    if (vies_check.length && !woolab.soap) {
      vies_check.prop('disabled', true).prop('checked', false);
    }
    function update() {
      $.each(requires, function (option, checks) {
        var on = $(checks).filter(':checked').length > 0;
        $(option).prop('disabled', !on);
        if (!on) {
          $(option).prop('checked', false);
        }
      });
    }
    $('#woolab_icdic_ares_check, #woolab_icdic_sk_check, #woolab_icdic_vies_check').on('change', update);
    update();
  });
})(jQuery);