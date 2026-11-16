/* Feed-Generator: Felder per AJAX nachladen, Filterzeilen verwalten */
(function () {
    'use strict';

    function init(form) {
        if (!form || form.dataset.yfgReady) {
            return;
        }
        form.dataset.yfgReady = '1';

        var api = form.dataset.api;
        var tableSelect = form.querySelector('#yfg-table');
        var status = form.querySelector('.yfg-loading');
        var tbody = form.querySelector('.yfg-filters tbody');
        var template = form.querySelector('#yfg-filter-template');
        var fields = null;
        var firstLoad = true;
        var counter = tbody.querySelectorAll('tr').length;

        function request(params) {
            var url = new URL(api, window.location.href);
            Object.keys(params).forEach(function (k) {
                url.searchParams.set(k, params[k]);
            });
            return fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) {
                    return r.json().then(function (data) {
                        if (!r.ok || data.error) {
                            throw new Error(data.error || ('HTTP ' + r.status));
                        }
                        return data;
                    });
                });
        }

        function fieldByName(name) {
            if (!fields) {
                return null;
            }
            for (var i = 0; i < fields.length; i++) {
                if (fields[i].name === name) {
                    return fields[i];
                }
            }
            return null;
        }

        function fillFieldSelect(select) {
            var current = select.value || select.dataset.selected || '';
            var emptyOption = select.querySelector('option[value=""]');
            var emptyLabel = emptyOption ? emptyOption.textContent : '';
            select.innerHTML = '';
            if (emptyOption) {
                select.appendChild(new Option(emptyLabel, ''));
            }
            fields.forEach(function (f) {
                var opt = new Option(f.label + ' [' + f.name + ', ' + f.type + ']', f.name);
                select.appendChild(opt);
            });
            if (fieldByName(current)) {
                select.value = current;
            }
            select.dataset.selected = select.value;
        }

        function loadFields(table) {
            if (!table) {
                return;
            }
            status.textContent = 'Felder werden geladen …';
            request({ action: 'fields', table: table })
                .then(function (data) {
                    fields = data.fields;
                    form.querySelectorAll('select.yfg-field').forEach(fillFieldSelect);
                    var profile = form.querySelector('#yfg-profile');
                    var currentProfile = profile.value;
                    profile.innerHTML = '';
                    profile.appendChild(new Option('– keins –', '0'));
                    Object.keys(data.profiles).forEach(function (id) {
                        profile.appendChild(new Option(data.profiles[id], id));
                    });
                    profile.value = data.profiles[currentProfile] ? currentProfile : '0';
                    // Werte der vorherigen Tabelle verwerfen und für die neue laden
                    tbody.querySelectorAll('tr').forEach(function (row) {
                        if (!firstLoad) {
                            row.querySelector('.yfg-values').innerHTML = '';
                        }
                        updateFilterRow(row, !firstLoad);
                    });
                    firstLoad = false;
                    status.textContent = fields.length + ' Felder geladen.';
                })
                .catch(function (e) {
                    status.textContent = 'Felder konnten nicht geladen werden: ' + e.message;
                });
        }

        function updateFilterRow(row, reloadOptions) {
            var fieldSelect = row.querySelector('select.yfg-field');
            var op = row.querySelector('.yfg-op').value;
            var valueInput = row.querySelector('.yfg-value');
            var valuesSelect = row.querySelector('.yfg-values');
            var info = fieldByName(fieldSelect.value);
            var isRelation = !!(info && info.relation);
            var needsValue = op !== 'empty' && op !== 'notempty';
            var useList = isRelation && ['any', 'all', 'none'].indexOf(op) > -1;

            valueInput.hidden = !needsValue || useList;
            valuesSelect.hidden = !needsValue || !useList;

            if (useList && (reloadOptions || valuesSelect.options.length === 0)) {
                if (!info.relation.supported) {
                    valuesSelect.innerHTML = '';
                    status.textContent = 'Diese Relationsart (1:n inline) wird für Filter nicht unterstützt.';
                    return;
                }
                var selected = Array.prototype.map.call(valuesSelect.selectedOptions, function (o) { return o.value; });
                request({ action: 'options', table: tableSelect.value, field: info.name })
                    .then(function (data) {
                        valuesSelect.innerHTML = '';
                        data.options.forEach(function (o) {
                            var opt = new Option(o.label, String(o.id));
                            opt.selected = selected.indexOf(String(o.id)) > -1;
                            valuesSelect.appendChild(opt);
                        });
                        status.textContent = data.options.length + ' Werte für „' + info.label + '“ geladen.';
                    })
                    .catch(function (e) {
                        status.textContent = 'Werte konnten nicht geladen werden: ' + e.message;
                    });
            }
        }

        tableSelect.addEventListener('change', function () {
            loadFields(tableSelect.value);
        });

        tbody.addEventListener('change', function (event) {
            var row = event.target.closest('.yfg-filter');
            if (!row) {
                return;
            }
            if (event.target.classList.contains('yfg-field')) {
                row.querySelector('.yfg-values').innerHTML = '';
                updateFilterRow(row, true);
            } else if (event.target.classList.contains('yfg-op')) {
                updateFilterRow(row, false);
            }
        });

        tbody.addEventListener('click', function (event) {
            var button = event.target.closest('.yfg-remove');
            if (button) {
                var row = button.closest('tr');
                row.parentNode.removeChild(row);
                form.querySelector('.yfg-add').focus();
                status.textContent = 'Filter entfernt.';
            }
        });

        form.querySelector('.yfg-add').addEventListener('click', function () {
            var html = template.innerHTML.replace(/__i__/g, 'n' + (counter++));
            tbody.insertAdjacentHTML('beforeend', html);
            var row = tbody.lastElementChild;
            var select = row.querySelector('select.yfg-field');
            if (fields) {
                fillFieldSelect(select);
            }
            updateFilterRow(row, true);
            select.focus();
            status.textContent = 'Filter hinzugefügt.';
        });

        if (tableSelect.value) {
            loadFields(tableSelect.value);
        }
    }

    function boot() {
        document.querySelectorAll('form.yfg-form').forEach(init);
    }

    if (window.jQuery) {
        window.jQuery(document).on('rex:ready', boot);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
