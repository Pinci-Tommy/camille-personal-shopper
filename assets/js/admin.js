/**
 * Camille settings page (WooCommerce > Camille): category tree, live estimate of the product
 * selection, and the sync progress panel. Data and texts come from PHP in window.CamillePsAdmin.
 */
(function () {
  'use strict';

  var data = window.CamillePsAdmin;
  if (!data) {
    return;
  }
  var texts = data.texts || {};

  function format(template, values) {
    var i = 0;
    return String(template).replace(/%(\d+\$)?s/g, function (match, position) {
      var index = position ? parseInt(position, 10) - 1 : i++;
      return values[index] != null ? String(values[index]) : '';
    });
  }

  function number(value) {
    try {
      return Number(value).toLocaleString(document.documentElement.lang || undefined);
    } catch (e) {
      return String(value);
    }
  }

  /* ------------------------------------------------------------ product selection */

  function setupSelection() {
    var form = document.querySelector('#camille-ps-products form');
    if (!form) {
      return;
    }
    var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="included_categories[]"]'));
    var children = {};
    boxes.forEach(function (box) {
      var parent = box.getAttribute('data-parent') || '0';
      (children[parent] = children[parent] || []).push(box);
    });

    function descendants(id) {
      var out = [];
      (children[id] || []).forEach(function (child) {
        out.push(child);
        out = out.concat(descendants(child.value));
      });
      return out;
    }

    /* A category left out takes its subcategories with it: they follow and cannot be ticked alone. */
    function cascade(box) {
      descendants(box.value).forEach(function (child) {
        child.checked = box.checked && !box.disabled;
        child.disabled = !box.checked || box.disabled;
      });
    }

    boxes.forEach(function (box) {
      if (!box.checked) {
        cascade(box);
      }
      box.addEventListener('change', function () {
        cascade(box);
        updateEstimate();
      });
    });

    var minInput = form.querySelector('input[name="price_min"]');
    var maxInput = form.querySelector('input[name="price_max"]');
    [minInput, maxInput].forEach(function (input) {
      if (input) {
        input.addEventListener('input', updateEstimate);
      }
    });

    var estimate = form.querySelector('.camille-ps-estimate strong');
    var products = Array.isArray(data.products) ? data.products : null;

    function readPrice(input) {
      if (!input || String(input.value).trim() === '') {
        return null;
      }
      var value = parseFloat(String(input.value).replace(',', '.'));
      return isFinite(value) && value > 0 ? value : null;
    }

    function updateEstimate() {
      if (!estimate || !products) {
        return;
      }
      var excluded = {};
      boxes.forEach(function (box) {
        if (!box.checked) {
          excluded[box.value] = true;
        }
      });
      var min = readPrice(minInput);
      var max = readPrice(maxInput);
      if (min !== null && max !== null && min > max) {
        var swap = min;
        min = max;
        max = swap;
      }
      var allowed = 0;
      products.forEach(function (row) {
        if (row[4]) {
          return;
        }
        var cats = row[3] || [];
        if (cats.length && cats.every(function (id) { return excluded[String(id)]; })) {
          return;
        }
        if (row[1] !== null && row[2] !== null) {
          if ((min !== null && row[2] < min) || (max !== null && row[1] > max)) {
            return;
          }
        }
        allowed++;
      });
      estimate.textContent = format(texts.estimate, [number(allowed), number(products.length)]);
    }
  }

  /* ------------------------------------------------------------ personal shopper */

  /* The gallery shows the portraits of the chosen gender (the shop's own photo fits both); a new
     photo picked from the computer is shown in place of the selected portrait until saved. */
  function setupPersona() {
    var form = document.querySelector('#camille-ps-persona form');
    if (!form) {
      return;
    }
    var options = Array.prototype.slice.call(form.querySelectorAll('.camille-ps-avatar-option'));
    var genders = Array.prototype.slice.call(form.querySelectorAll('input[name="persona_gender"]'));
    var file = form.querySelector('input[name="persona_photo"]');

    function currentGender() {
      var picked = genders.filter(function (input) { return input.checked; })[0];
      return picked ? picked.value : 'female';
    }

    function filter() {
      var gender = currentGender();
      var selectedHidden = false;
      options.forEach(function (option) {
        var fits = option.getAttribute('data-gender') === 'any' || option.getAttribute('data-gender') === gender;
        option.hidden = !fits;
        var radio = option.querySelector('input');
        if (!fits && radio.checked) {
          radio.checked = false;
          selectedHidden = true;
        }
      });
      if (selectedHidden || !options.some(function (o) { return !o.hidden && o.querySelector('input').checked; })) {
        var first = options.filter(function (o) { return !o.hidden; })[0];
        if (first && !(file && file.files && file.files.length)) {
          first.querySelector('input').checked = true;
        }
      }
    }

    genders.forEach(function (input) {
      input.addEventListener('change', filter);
    });
    options.forEach(function (option) {
      option.querySelector('input').addEventListener('change', function () {
        if (file) {
          file.value = '';
        }
      });
    });
    if (file) {
      file.addEventListener('change', function () {
        if (file.files && file.files.length) {
          options.forEach(function (option) {
            option.querySelector('input').checked = false;
          });
        }
      });
    }
    filter();
  }

  /* ------------------------------------------------------------ sync progress */

  var ACTIVE = { reading: 0, saving: 1, studying: 2 };
  var panel = document.querySelector('#camille-ps-catalog .camille-ps-progress');
  var lineTimer = null;
  var lineIndex = 0;
  var lineStage = '';
  var sawActive = false;

  function el(selector) {
    return panel.querySelector(selector);
  }

  function setLines(stage) {
    var lines = (texts.lines && texts.lines[stage]) || [];
    if (stage === lineStage) {
      return;
    }
    lineStage = stage;
    lineIndex = 0;
    if (lineTimer) {
      clearInterval(lineTimer);
      lineTimer = null;
    }
    var target = el('.camille-ps-progress-line');
    target.textContent = lines.length ? lines[0] : '';
    if (lines.length > 1) {
      lineTimer = setInterval(function () {
        lineIndex = (lineIndex + 1) % lines.length;
        target.classList.remove('is-shown');
        void target.offsetWidth;
        target.textContent = lines[lineIndex];
        target.classList.add('is-shown');
      }, 4500);
    }
  }

  function renderSteps(stage) {
    var list = el('.camille-ps-steps');
    list.innerHTML = '';
    var current = ACTIVE[stage];
    (texts.stepNames || []).forEach(function (name, index) {
      var item = document.createElement('li');
      item.textContent = name;
      if (stage === 'done' || (current !== undefined && index < current)) {
        item.className = 'is-done';
      } else if (index === current) {
        item.className = 'is-current';
      }
      list.appendChild(item);
    });
  }

  function renderExtra(progress) {
    var box = el('.camille-ps-progress-extra');
    box.innerHTML = '';
    if (progress.photosText && progress.stage !== 'failed') {
      var photos = document.createElement('p');
      photos.className = 'camille-ps-badge is-info';
      photos.textContent = progress.photosText;
      box.appendChild(photos);
    }
    if (progress.stage === 'failed' && progress.messageText) {
      var error = document.createElement('p');
      error.className = 'camille-ps-badge is-error';
      error.textContent = progress.messageText;
      box.appendChild(error);
    }
    if (progress.stage !== 'done' || !progress.leftOutText) {
      return;
    }
    var details = document.createElement('details');
    details.className = 'camille-ps-rejected';
    var summary = document.createElement('summary');
    summary.textContent = progress.leftOutText;
    details.appendChild(summary);
    var list = document.createElement('ul');
    (progress.rejected || []).forEach(function (item) {
      var li = document.createElement('li');
      var label;
      if (item.link) {
        label = document.createElement('a');
        label.href = item.link;
      } else {
        label = document.createElement('span');
      }
      label.textContent = item.label;
      li.appendChild(label);
      li.appendChild(document.createTextNode(' – ' + (item.reasonText || '')));
      list.appendChild(li);
    });
    details.appendChild(list);
    box.appendChild(details);
  }

  function render(progress) {
    var stage = progress.stage;
    if (stage === 'idle') {
      panel.hidden = true;
      return;
    }
    panel.hidden = false;
    panel.setAttribute('data-stage', stage);
    var isActive = ACTIVE[stage] !== undefined;
    if (isActive) {
      sawActive = true;
    }
    var stages = texts.stages || {};
    var title = stages[stage] || '';
    if (stage === 'done') {
      title = sawActive ? stages.ready : stages.updated;
    }
    el('.camille-ps-progress-title').textContent = title;
    renderSteps(stage);
    setLines(isActive ? stage : '');
    if (!isActive) {
      el('.camille-ps-progress-line').textContent = stage === 'done' ? progress.itemsText || '' : '';
    }

    var bar = el('.camille-ps-bar');
    var fill = bar.querySelector('span');
    var percent = null;
    var meta = '';
    if (stage === 'reading' || stage === 'saving') {
      if (progress.total > 0) {
        percent = Math.min(100, Math.round((progress.done / progress.total) * 100));
        meta = format(stage === 'reading' ? texts.readCount : texts.saveCount, [number(progress.done), number(progress.total)]);
      }
      if (progress.isBusy) {
        meta = texts.busy;
      }
    } else if (stage === 'studying') {
      if (progress.progress > 0) {
        percent = Math.round(progress.progress * 100);
      }
      var minutes = progress.remainingMinutes;
      if (minutes === null || minutes === undefined) {
        meta = '';
      } else if (minutes <= 1) {
        meta = progress.progress >= 0.95 ? texts.almostDone : texts.oneMinute;
      } else {
        meta = format(texts.minutesLeft, [number(minutes)]);
      }
    }
    bar.hidden = !isActive;
    bar.classList.toggle('is-indeterminate', isActive && percent === null);
    fill.style.width = percent === null ? '' : percent + '%';
    if (percent !== null) {
      bar.setAttribute('aria-valuenow', String(percent));
    } else {
      bar.removeAttribute('aria-valuenow');
    }
    if (isActive && texts.leavePage) {
      meta = meta ? meta + ' · ' + texts.leavePage : texts.leavePage;
    }
    el('.camille-ps-progress-meta').textContent = meta;
    renderExtra(progress);
    /* The button said "Restart the sync" while it ran. */
    var button = document.querySelector('#camille-ps-catalog input[type="submit"]');
    if (button && !isActive && texts.syncButton) {
      button.value = texts.syncButton;
    }
  }

  function poll() {
    var body = new FormData();
    body.append('action', data.action);
    body.append('nonce', data.nonce);
    fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(function (answer) {
        if (answer && answer.success && answer.data) {
          render(answer.data);
          schedule(answer.data);
        } else {
          setTimeout(poll, data.pollMs * 3);
        }
      })
      .catch(function () {
        setTimeout(poll, data.pollMs * 3);
      });
  }

  function schedule(progress) {
    var isActive = ACTIVE[progress.stage] !== undefined;
    if (!isActive && progress.photosPending && !document.hidden) {
      /* The photos are copied in the background: a slower look, until the copy is over. */
      setTimeout(poll, data.pollMs * 4);
    } else if (isActive && !document.hidden) {
      setTimeout(poll, data.pollMs);
    } else if (isActive || progress.photosPending) {
      document.addEventListener('visibilitychange', function resume() {
        if (!document.hidden) {
          document.removeEventListener('visibilitychange', resume);
          poll();
        }
      });
    }
  }

  function setupProgress() {
    if (!panel || !data.progress) {
      return;
    }
    render(data.progress);
    schedule(data.progress);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      setupSelection();
      setupPersona();
      setupProgress();
    });
  } else {
    setupSelection();
    setupPersona();
    setupProgress();
  }
})();
