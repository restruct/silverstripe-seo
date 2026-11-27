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
// - The icon controls of a finding (.seo-checks__tip) get the admin's Bootstrap tooltip; "About this check"
//   (.seo-checks__about) opens the module's spec of the check in a Bootstrap modal, rendered by the CMS endpoint.
//   Both are the admin's own Bootstrap 5 (tooltip.js is loaded by the admin, modal.js by this module's config),
//   used through their jQuery bridge. Without them the button keeps its native title and the link opens the
//   published spec in a new tab.
// - The preview (.seo-snippet) follows the Title, Meta title and Meta description fields on every keystroke,
//   with the thresholds and width table the server put in data-seo-config.
(function ($) {
  $.entwine('restruct.seo', function ($) {
    // How long to wait before re-checking, so a stale panel and the submit event of the same save collapse
    // into one request
    var RECHECK_DELAY = 150;

    // The admin's Bootstrap plugins. jQuery UI (also in the admin) has a $.fn.tooltip of its own; Bootstrap's
    // jQuery bridge is the one with a Constructor
    function hasBootstrapTooltip() {
      return !!($.fn.tooltip && $.fn.tooltip.Constructor);
    }

    function hasBootstrapModal() {
      return !!($.fn.modal && $.fn.modal.Constructor);
    }

    // Numbers the dialogs' title ids, for aria-labelledby
    var docCount = 0;

    // A new element with classes. Built with createElement rather than a $('<tag ...>') string: the static
    // selector test (tests/Checks/EntwineSelectorsTest.php) reads every $('...') up to a .entwine( call
    function el(tag, className) {
      return $(document.createElement(tag)).addClass(className || '');
    }

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

      // The "About this check" dialog: opened at once with a loading line, filled with the spec the endpoint
      // rendered (escaped there, with only the renderer's own tags), removed again when closed. When the spec
      // cannot be loaded, the dialog says so and links to the published spec instead
      showDoc: function (url, fallbackHref, title) {
        var self = this;
        var titleId = 'seo-checks-doc-title-' + (++docCount);
        var heading = el('h5', 'modal-title').attr('id', titleId).text(title || '');
        var close = el('button', 'btn-close')
          .attr({type: 'button', 'data-bs-dismiss': 'modal', 'aria-label': this.message('data-msg-close', 'Close')});
        var body = el('div', 'modal-body').append(
          el('p').text(this.message('data-msg-loading', 'Loading...'))
        );
        var modal = el('div', 'modal fade seo-checks-doc')
          .attr({tabindex: '-1', 'aria-hidden': 'true', 'aria-labelledby': titleId})
          .append(el('div', 'modal-dialog modal-lg modal-dialog-scrollable').append(
            el('div', 'modal-content').append(
              el('div', 'modal-header').append(heading, close),
              body
            )
          ));
        modal.on('hidden.bs.modal', function () {
          modal.modal('dispose');
          modal.remove();
        });
        modal.appendTo(document.body).modal('show');

        $.ajax({
          url: url,
          type: 'GET',
          dataType: 'json'
        }).done(function (response) {
          if (response && typeof response.html === 'string') {
            if (response.title) {
              // Escaped by the endpoint (text only, no tags): set as markup, or an & would show as &amp;
              heading.html(response.title);
            }
            body.html(response.html);
          } else {
            failed(200, response);
          }
        }).fail(function (xhr) {
          failed(xhr.status, xhr.responseText);
        });

        function failed(status, detail) {
          var text = self.message('data-msg-doc-failed', 'Could not load the explanation.');
          body.empty().append(el('p').text(text));
          if (fallbackHref) {
            body.append(el('p').append(
              el('a').attr({href: fallbackHref, target: '_blank', rel: 'noopener noreferrer'})
                .text(self.message('data-msg-doc-link', 'Open the documentation'))
            ));
          }
          if (window.console && window.console.error) {
            window.console.error('[seo-checks] ' + text, {url: url, status: status, detail: detail});
          }
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

    // The admin initialises its tooltips once, on page load; the panel arrives later (and again after every
    // re-check), so each control starts its own and disposes of it when it leaves, or its tip would stay behind
    $('.cms-edit-form .seo-checks .seo-checks__tip').entwine({
      onmatch: function () {
        this._super();
        if (hasBootstrapTooltip()) {
          this.tooltip();
        }
      },

      onunmatch: function () {
        if (hasBootstrapTooltip() && $.fn.tooltip.Constructor.getInstance(this[0])) {
          this.tooltip('dispose');
        }
        this._super();
      }
    });

    // A module check's spec in a dialog; otherwise (a project's check, or no Bootstrap modal) the link opens the
    // published spec in a new tab as it would without this script
    $('.cms-edit-form .seo-checks .seo-checks__about').entwine({
      onclick: function (e) {
        var url = this.attr('data-doc-url');
        if (!url || !hasBootstrapModal()) {
          return;
        }
        e.preventDefault();
        if (hasBootstrapTooltip() && $.fn.tooltip.Constructor.getInstance(this[0])) {
          this.tooltip('hide');
        }
        var item = this.closest('.seo-checks__item');
        this.closest('.seo-checks').showDoc(url, this.attr('href'), $.trim(item.find('.seo-checks__title').text()));
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
