// SEO checks panel and search result preview in the page edit form (restruct/silverstripe-seo).
//
// Plain JavaScript on the admin's own jQuery + entwine, without a build step: this file is the source and is
// served as-is, which is why it lives in client/dist and there is no client/src.
//
// The edit form is rendered by the server and swapped in by the admin's PJAX navigation, so every behaviour is
// an entwine rule on markup inside the form: rules match whenever a form arrives, without observers.
// Rules select by class only: the admin's entwine does not match a valueless attribute test such as
// '.seo-checks[data-seo-checks]', and then the rule's methods simply do not exist on the element (no error).
// tests/Checks/EntwineSelectorsTest.php fails on any attribute test in an entwine selector.
// - The panel (.seo-checks) re-checks when the server says its stored result is missing or stale, after every
//   form submit (a save that only changed blocks does not mark the page stale), and on the Re-check button.
//   The endpoint answers with the panel's fresh markup, which replaces the old element.
// - The preview (.seo-snippet) follows the Title, Meta title and Meta description fields on every keystroke,
//   with the thresholds and width table the server put in data-seo-config.
(function ($) {
  $.entwine('restruct.seo', function ($) {
    // How long to wait before re-checking, so a stale panel and the submit event of the same save collapse
    // into one request
    var RECHECK_DELAY = 150;

    $('.cms-edit-form .seo-checks').entwine({
      onmatch: function () {
        this._super();
        if (String(this.attr('data-seo-stale')) === '1') {
          this.scheduleRecheck();
        }
      },

      onunmatch: function () {
        var timer = this.data('seoTimer');
        if (timer) {
          window.clearTimeout(timer);
        }
        this._super();
      },

      scheduleRecheck: function () {
        var self = this;
        var timer = this.data('seoTimer');
        if (timer) {
          window.clearTimeout(timer);
        }
        this.data('seoTimer', window.setTimeout(function () {
          self.data('seoTimer', null);
          self.send(self.attr('data-recheck-url'), {});
        }, RECHECK_DELAY));
      },

      // A message the server translated and put in a data-msg-* attribute, with {name} placeholders filled in
      message: function (attribute, fallback, params) {
        var text = this.attr(attribute) || fallback;
        $.each(params || {}, function (key, value) {
          text = text.split('{' + key + '}').join(String(value));
        });
        return text;
      },

      // Shown next to the Re-check button, and logged with what went wrong, so a check never fails silently
      showFailure: function (text, detail) {
        this.removeClass('seo-checks--busy');
        this.find('.seo-checks__status').text(text);
        if (window.console && window.console.error) {
          window.console.error('[seo-checks] ' + text, detail);
        }
      },

      // POST to the endpoint with the page ID and CSRF token of the edit form this panel sits in
      send: function (url, extra) {
        var self = this;
        var form = this.closest('form');
        var data = $.extend({
          ID: form.find(':input[name=ID]').val(),
          SecurityID: form.find(':input[name=SecurityID]').val()
        }, extra);
        if (!url || !data.ID || !data.SecurityID) {
          this.showFailure(this.message('data-msg-not-started', 'Could not start the check.'), {
            url: url,
            ID: data.ID,
            hasSecurityID: !!data.SecurityID,
            inForm: form.length > 0
          });
          return;
        }
        this.addClass('seo-checks--busy');
        this.find('.seo-checks__status').text(this.message('data-msg-checking', 'Checking...'));
        $.ajax({
          url: url,
          type: 'POST',
          data: data,
          dataType: 'json'
        }).done(function (response) {
          if (response && typeof response.panel === 'string') {
            // The server rendered and escaped this markup; it replaces the panel, and the new element matches
            // this rule again (with data-seo-stale="0", so it does not re-check)
            self.replaceWith($($.parseHTML($.trim(response.panel))));
          } else {
            self.showFailure(self.message('data-msg-failed', 'Check failed (HTTP {status}).', {status: 200}), response);
          }
        }).fail(function (xhr) {
          // The endpoint answers refusals as AdminController::jsonError() JSON, {errors: [{value: message}]}
          // (an expired security token, say)
          var reason = '';
          try {
            var body = JSON.parse(xhr.responseText) || {};
            reason = body.errors && body.errors[0] && body.errors[0].value ? String(body.errors[0].value) : '';
          } catch (err) {
            reason = '';
          }
          var text = self.message('data-msg-failed', 'Check failed (HTTP {status}).', {status: xhr.status});
          self.showFailure(reason ? text + ' ' + reason : text, {status: xhr.status, body: xhr.responseText});
        });
      }
    });

    $('.cms-edit-form .seo-checks .seo-checks__recheck').entwine({
      onclick: function (e) {
        e.preventDefault();
        this.closest('.seo-checks').scheduleRecheck();
      }
    });

    $('.cms-edit-form .seo-checks .seo-checks__ignore').entwine({
      onclick: function (e) {
        e.preventDefault();
        var panel = this.closest('.seo-checks');
        panel.send(panel.attr('data-ignore-url'), {
          Check: this.attr('data-check'),
          Ignore: this.attr('data-ignore')
        });
      }
    });

    // After every save, publish or other submit of the edit form: the form element has been replaced by the
    // response by the time this fires
    $('.cms-edit-form').entwine({
      onaftersubmitform: function (e, data) {
        this._super(e, data);
        this.find('.seo-checks').scheduleRecheck();
      },

      // The preview follows the title and description fields; listened for on the form, as a rule on the
      // inputs would need an attribute selector (name=...), which entwine does not match reliably
      oninput: function (e) {
        this._super(e);
        var name = e && e.target ? e.target.name : '';
        if (name === 'MetaTitle' || name === 'Title' || name === 'MetaDescription') {
          this.find('.seo-snippet').updatePreview();
        }
      }
    });

    // --- Search result preview -------------------------------------------------------------------------------

    $('.cms-edit-form .seo-snippet').entwine({
      onmatch: function () {
        this._super();
        this.updatePreview();
      },

      getSeoConfig: function () {
        try {
          return JSON.parse(this.attr('data-seo-config'));
        } catch (err) {
          return null;
        }
      },

      updatePreview: function () {
        var config = this.getSeoConfig();
        var form = this.closest('form');
        if (!config || !form.length) {
          return;
        }
        var metaTitle = $.trim(form.find(':input[name=MetaTitle]').val() || '');
        var pageTitle = $.trim(form.find(':input[name=Title]').val() || '');
        // A title the page generates in code (a getter, or a locked MetaTitle field) comes from PHP: the inputs
        // would show what the page does not output
        var ownTitle = config.title.pageTitle ? config.title.pageTitle : (metaTitle || pageTitle);
        var title = buildTitle(ownTitle, config.title);
        var typed = $.trim(form.find(':input[name=MetaDescription]').val() || '');
        var description = typed || config.description.fallback || '';

        var pixels = Math.round(titleWidth(title, config.title));
        this.find('[data-seo-preview=title]').text(title);
        this.find('[data-seo-preview=description]').text(truncate(description, config.description.max));
        this.find('[data-seo-value=pixels]').text(pixels);
        this.find('[data-seo-value=length]').text(description.length);
        this.find('[data-seo-meter=title]').toggleClass('seo-snippet__meter--over', pixels > config.title.maxPixels);
        this.find('[data-seo-meter=description]').toggleClass(
          'seo-snippet__meter--over',
          description.length > 0 && (description.length < config.description.min || description.length > config.description.max)
        );
      }
    });

    // The page title plus the site name, as the server builds it (SeoSiteTreeExtension::SeoTitle): no suffix
    // when the title already is, or ends with, the site name after a separator
    function buildTitle(title, config) {
      var suffix = config.suffix || '';
      if (!config.appendSiteName || !suffix) {
        return title;
      }
      if (!title) {
        return suffix;
      }
      if (endsWithSiteName(title, suffix)) {
        return title;
      }
      return title + config.separator + suffix;
    }

    function endsWithSiteName(title, suffix) {
      var t = title.toLowerCase();
      var s = suffix.toLowerCase();
      if (t === s) {
        return true;
      }
      if (t.length <= s.length || t.slice(-s.length) !== s) {
        return false;
      }
      // A separator is anything that is neither a letter, a digit nor a space, with optional spaces around it
      var before = $.trim(t.slice(0, -s.length));
      var last = before.slice(-1);
      return last !== '' && !/[\s0-9A-Za-zÀ-ɏ]/.test(last);
    }

    // Same table and arithmetic as TitleWidth::pixels(): accented letters measured as their base letter
    function titleWidth(text, config) {
      var plain = text.normalize ? text.normalize('NFD').replace(/[̀-ͯ]/g, '') : text;
      var units = 0;
      for (var i = 0; i < plain.length; i++) {
        var w = config.widths[plain.charAt(i)];
        units += typeof w === 'number' ? w : config.defaultWidth;
      }
      return units * config.fontSize / 1000;
    }

    function truncate(text, max) {
      return text.length > max ? $.trim(text.slice(0, max)) + '...' : text;
    }
  });
}(jQuery));
