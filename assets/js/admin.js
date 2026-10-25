"use strict";

(function ($) {
  $(document).ready(function () {
    // Option => the options it needs: an autofill needs its check, ignoring a
    // failed check needs at least one check.
    var requires = {
      ares_fill: ['ares_check'],
      sk_fill: ['sk_check'],
      ignore_check_fail: ['ares_check', 'sk_check', 'vies_check']
    };

    // Fields are named woolab_icdic_settings[key] (includes/options.php).
    function field(key) {
      return $(document.getElementById('woolab_icdic_settings[' + key + ']'));
    }

    // Disabled by the server (a filter decides, an extension is missing): leave as is.
    var locked = {};
    $.each(requires, function (option) {
      locked[option] = field(option).prop('disabled');
    });

    // Says why an option is disabled, under its checkbox.
    function note(option, text) {
      var $cell = field(option).closest('td');
      $cell.find('.woolab-icdic-requires').remove();
      if (text) {
        $('<p class="description woolab-icdic-requires" style="color:#ca4a1f"></p>').text(text).appendTo($cell);
      }
    }
    function update() {
      $.each(requires, function (option, checks) {
        if (locked[option]) {
          return;
        }
        var on = checks.some(function (key) {
          return field(key).prop('checked');
        });
        field(option).prop('disabled', !on);
        if (!on) {
          field(option).prop('checked', false);
        }
        note(option, on ? '' : woolabIcdicAdmin.requires[option]);
      });
    }
    $.each(['ares_check', 'sk_check', 'vies_check'], function (i, key) {
      field(key).on('change', update);
    });
    update();
  });
})(jQuery);