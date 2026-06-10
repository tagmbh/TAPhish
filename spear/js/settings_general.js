getSetingsValues();

$("#selector_timezone").select2({
    minimumResultsForSearch: -1
});
$("#selector_date_format").select2({
    minimumResultsForSearch: -1
});
$("#selector_space_format").select2({
    minimumResultsForSearch: -1
});
$("#selector_time_format").select2({
    minimumResultsForSearch: -1
});

$("#lb_selector_time_format").show();

$(function() {
    $('#selector_timezone, #selector_date_format, #selector_space_format, #selector_time_format').on("change", function(e) {
        timeSelected();
    });
});

//------------------------Timezone Section----------------------------
$(document).ready(function() {
    const timezones = [
        "Etc/GMT+12",
        "Pacific/Midway",
        "Pacific/Honolulu",
        "America/Juneau",
        "America/Dawson",
        "America/Boise",
        "America/Chihuahua",
        "America/Phoenix",
        "America/Chicago",
        "America/Regina",
        "America/Mexico_City",
        "America/Belize",
        "America/Detroit",
        "America/Indiana/Indianapolis",
        "America/Bogota",
        "America/Glace_Bay",
        "America/Caracas",
        "America/Santiago",
        "America/St_Johns",
        "America/Sao_Paulo",
        "America/Argentina/Buenos_Aires",
        "America/Godthab",
        "Etc/GMT+2",
        "Atlantic/Azores",
        "Atlantic/Cape_Verde",
        "GMT",
        "Africa/Casablanca",
        "Atlantic/Canary",
        "Europe/Belgrade",
        "Europe/Sarajevo",
        "Europe/Brussels",
        "Europe/Amsterdam",
        "Africa/Algiers",
        "Europe/Bucharest",
        "Africa/Cairo",
        "Europe/Helsinki",
        "Europe/Athens",
        "Asia/Jerusalem",
        "Africa/Harare",
        "Europe/Moscow",
        "Asia/Kuwait",
        "Africa/Nairobi",
        "Asia/Baghdad",
        "Asia/Tehran",
        "Asia/Dubai",
        "Asia/Baku",
        "Asia/Kabul",
        "Asia/Yekaterinburg",
        "Asia/Karachi",
        "Asia/Kolkata",
        "Asia/Kathmandu",
        "Asia/Dhaka",
        "Asia/Colombo",
        "Asia/Almaty",
        "Asia/Rangoon",
        "Asia/Bangkok",
        "Asia/Krasnoyarsk",
        "Asia/Shanghai",
        "Asia/Kuala_Lumpur",
        "Asia/Taipei",
        "Australia/Perth",
        "Asia/Irkutsk",
        "Asia/Seoul",
        "Asia/Tokyo",
        "Asia/Yakutsk",
        "Australia/Darwin",
        "Australia/Adelaide",
        "Australia/Sydney",
        "Australia/Brisbane",
        "Australia/Hobart",
        "Asia/Vladivostok",
        "Pacific/Guam",
        "Asia/Magadan",
        "Pacific/Fiji",
        "Pacific/Auckland",
        "Pacific/Tongatapu"
    ];

    const i18n = {
        "Etc/GMT+12": "International Date Line West",
        "Pacific/Midway": "Midway Island, Samoa",
        "Pacific/Honolulu": "Hawaii",
        "America/Juneau": "Alaska",
        "America/Dawson": "Pacific Time (US and Canada); Tijuana",
        "America/Boise": "Mountain Time (US and Canada)",
        "America/Chihuahua": "Chihuahua, La Paz, Mazatlan",
        "America/Phoenix": "Arizona",
        "America/Chicago": "Central Time (US and Canada)",
        "America/Regina": "Saskatchewan",
        "America/Mexico_City": "Guadalajara, Mexico City, Monterrey",
        "America/Belize": "Central America",
        "America/Detroit": "Eastern Time (US and Canada)",
        "America/Indiana/Indianapolis": "Indiana (East)",
        "America/Bogota": "Bogota, Lima, Quito",
        "America/Glace_Bay": "Atlantic Time (Canada)",
        "America/Caracas": "Caracas, La Paz",
        "America/Santiago": "Santiago",
        "America/St_Johns": "Newfoundland and Labrador",
        "America/Sao_Paulo": "Brasilia",
        "America/Argentina/Buenos_Aires": "Buenos Aires, Georgetown",
        "America/Godthab": "Greenland",
        "Etc/GMT+2": "Mid-Atlantic",
        "Atlantic/Azores": "Azores",
        "Atlantic/Cape_Verde": "Cape Verde Islands",
        "GMT": "Dublin, Edinburgh, Lisbon, London",
        "Africa/Casablanca": "Casablanca, Monrovia",
        "Atlantic/Canary": "Canary Islands",
        "Europe/Belgrade": "Belgrade, Bratislava, Budapest, Ljubljana, Prague",
        "Europe/Sarajevo": "Sarajevo, Skopje, Warsaw, Zagreb",
        "Europe/Brussels": "Brussels, Copenhagen, Madrid, Paris",
        "Europe/Amsterdam": "Amsterdam, Berlin, Bern, Rome, Stockholm, Vienna",
        "Africa/Algiers": "West Central Africa",
        "Europe/Bucharest": "Bucharest",
        "Africa/Cairo": "Cairo",
        "Europe/Helsinki": "Helsinki, Kiev, Riga, Sofia, Tallinn, Vilnius",
        "Europe/Athens": "Athens, Istanbul, Minsk",
        "Asia/Jerusalem": "Jerusalem",
        "Africa/Harare": "Harare, Pretoria",
        "Europe/Moscow": "Moscow, St. Petersburg, Volgograd",
        "Asia/Kuwait": "Kuwait, Riyadh",
        "Africa/Nairobi": "Nairobi",
        "Asia/Baghdad": "Baghdad",
        "Asia/Tehran": "Tehran",
        "Asia/Dubai": "Abu Dhabi, Muscat",
        "Asia/Baku": "Baku, Tbilisi, Yerevan",
        "Asia/Kabul": "Kabul",
        "Asia/Yekaterinburg": "Ekaterinburg",
        "Asia/Karachi": "Islamabad, Karachi, Tashkent",
        "Asia/Kolkata": "Chennai, Kolkata, Mumbai, New Delhi",
        "Asia/Kathmandu": "Kathmandu",
        "Asia/Dhaka": "Astana, Dhaka",
        "Asia/Colombo": "Sri Jayawardenepura",
        "Asia/Almaty": "Almaty, Novosibirsk",
        "Asia/Rangoon": "Yangon Rangoon",
        "Asia/Bangkok": "Bangkok, Hanoi, Jakarta",
        "Asia/Krasnoyarsk": "Krasnoyarsk",
        "Asia/Shanghai": "Beijing, Chongqing, Hong Kong SAR, Urumqi",
        "Asia/Kuala_Lumpur": "Kuala Lumpur, Singapore",
        "Asia/Taipei": "Taipei",
        "Australia/Perth": "Perth",
        "Asia/Irkutsk": "Irkutsk, Ulaanbaatar",
        "Asia/Seoul": "Seoul",
        "Asia/Tokyo": "Osaka, Sapporo, Tokyo",
        "Asia/Yakutsk": "Yakutsk",
        "Australia/Darwin": "Darwin",
        "Australia/Adelaide": "Adelaide",
        "Australia/Sydney": "Canberra, Melbourne, Sydney",
        "Australia/Brisbane": "Brisbane",
        "Australia/Hobart": "Hobart",
        "Asia/Vladivostok": "Vladivostok",
        "Pacific/Guam": "Guam, Port Moresby",
        "Asia/Magadan": "Magadan, Solomon Islands, New Caledonia",
        "Pacific/Fiji": "Fiji Islands, Kamchatka, Marshall Islands",
        "Pacific/Auckland": "Auckland, Wellington",
        "Pacific/Tongatapu": "Nuku'alofa"
    }
    
    const _t = (s) => {
        if (i18n !== void 0 && i18n[s]) {
            return i18n[s];
        }
        return s;
    };

    const dateTimeUtc = moment("2017-06-05T19:41:03Z").utc();
    $("#selector_timezone").html(dateTimeUtc.format("ddd, DD MMM YYYY HH:mm:ss"));

    const selectorOptions = moment.tz.names()
        .filter(tz => {
            return timezones.includes(tz)
        })
        .reduce((memo, tz) => {
            memo.push({
                name: tz,
                offset: moment.tz(tz).utcOffset()
            });

            return memo;
        }, [])
        .sort((a, b) => {
            return a.offset - b.offset
        })
        .reduce((memo, tz) => {
            const timezone = tz.offset ? moment.tz(tz.name).format('Z') : '';

            return memo.concat(`<option value="${tz.name}">(GMT${timezone}) ${_t(tz.name)}</option>`);
        }, "");

    $("#selector_timezone").html(selectorOptions);
    $("#selector_timezone").val("Asia/Kuala_Lumpur");    
});

var date_formats={
    'd-m-y': '15-05-21',
    'd/m/y': '15/05/21',

    'd-m-o': '15-05-2021',
    'd/m/o': '15/05/2021',

    'd m y': '15 05 21',
    'd m o': '15 05 2021',
    'm d y': '05 15 21', 
    'm d o': '05 15 2021', 
    'F d y': 'May 15 21',
    'F d o': 'May 15 2021', 
 
    'd F y': '15 May 21', 
    'd F o': '15 May 2021', 
    'jS F y': '15th May 21', 
    'jS F o': '15th May 2021', 

    'y m d': '21 05 15', 
    'Y m d': '2021 05 15', 
    'y F d': '21 May 15', 
    'o F d': '2021 May 15', 
 
    'F/d/y': 'May/15/21', 
    'F/d/o': 'May/15/2021', 
    'F/jS/y': 'May/15th/21', 
    'F/jS/o': 'May/15th/2021', 

    'm/F/y': '15/May/21', 
    'm/F/o': '15/May/2021', 
    'jS/F/y': '4th/May/21', 
    'jS/F/o': '4th/May/2021',

    'm/d/y': '05/15/21',

    'o/m/d': '2021/05/15', 
    'y/F/d': '21/May/15', 

    'm-d-y': '05-15-21', 
    'm-d-o': '05-15-2021', 
    'F-d-y': 'May-15-21',
    'F-d-o': 'May-15-2021', 

    'm-F-y': '15-May-21', 
    'm-F-o': '15-May-2021', 
    'jS-F-y': '4th-May-21', 
    'jS-F-o': '4th-May-2021',

    'y-m-d': '21-05-15', 
    'Y-m-d': '2021-05-15', 
    'y-F-d': '21-May-15', 
    'o-F-d': '2021-May-15', 

    'Unix Timestamp-seconds': '1586079408',
    'Unix Timestamp-milliseconds': '1586079408510',
};
var selector_time_formats={
    'h:i': '02:38', 
    'H:i': '14:38', 
    'h:i:s': '02:38:30', 
    'H:i:s': '14:38:30',   
    'h:i a': '02:38 pm', 
    'h:i A': '02:38 PM', 
    'h:i:s a': '02:38:30 pm', 
    'h:i:s A': '02:38:30 PM', 
    'h:i:s.v': '02:38:30.152', 
    'H:i:s.v': '14:38:30.152', 
    'h:i:s:v': '02:38:30.152', 
    'H:i:s:v': '14:38:30.152', 
    'h:i:s:v a': '02:38:30.152 pm', 
    'h:i:s:v A': '02:38:30.152 PM',  
};
$.each(date_formats, function(name, value) {   
    $('#selector_date_format').append($("<option></option>").attr("value",name).text(value)); 
});
$.each(selector_time_formats, function(name, value) {   
    $('#selector_time_format').append($("<option></option>").attr("value",name).text(value)); 
});

function timeSelected(time_zone=null){
    var space_format;
    var date_format = $("#selector_date_format").val();
    var time_format = $("#selector_time_format").val(); 
    if(time_zone == null)
        time_zone = $("#selector_timezone").val();

    switch($("#selector_space_format").val()){
        case 'space': space_format = ' '; break;
        case 'comma': space_format = ','; break;
        case 'comaspace': space_format = ', ';
    }

    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "get_date_time_display",
            time_zone: time_zone,
            date_time_format: date_format + space_format + time_format,
        }),
    }).done(function (response) {
        $("#lb_selector_time_format").text(response.result);
    }); 
}
//------------------------------------------------------

function modifyTimeStampSettings(e){
    var time_zone = { "timezone":$("#selector_timezone").val(), "value":moment.tz($("#selector_timezone").val()).utcOffset() * 60};
    var time_format = {"date":$("#selector_date_format").val(), "space": $("#selector_space_format").val(), "time":$("#selector_time_format").val()};
    
    enableDisableMe(e);
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "modify_timestamp_settings",
            time_zone: time_zone,
            time_format: time_format,
         }),
    }).done(function (response) {
        if(response.result == "success"){ 
            toastr.success('', 'Settings saved successfully!');   
        }
        else
            toastr.error('', response.error);
        enableDisableMe(e);
    }); 
}

function getSetingsValues(){
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "get_timestamp_settings"
         })
    }).done(function (data) {
        if(!data.error){  // no data error
            $("#selector_timezone").val(data.time_zone.timezone).trigger("change");
            $("#selector_date_format").val(data.time_format.date).trigger("change");
            $("#selector_space_format").val(data.time_format.space).trigger("change");
            $("#selector_time_format").val(data.time_format.time).trigger("change");
            $("#setting_field_mail").val(data.contact_mail);
            $("#tb_sp_url").val(data.baseurl);
            timeSelected(data.time_zone.timezone);
        }
    }); 
}

function modifySPBaseURL(e){   
    var baseurl = $("#tb_sp_url").val();
    if (!isValidURL(baseurl)) {
        $('#tb_sp_url').addClass('is-invalid');
        return;
    } 
    else
        $('#tb_sp_url').removeClass('is-invalid');

    enableDisableMe(e);
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "modify_SP_base_URL",
            baseurl: baseurl
        }),
    }).done(function (response) {
        if(response.result == "success"){ 
            toastr.success('', 'URL updated successfully!');   
        }
        else
            toastr.error('', response.error);
        enableDisableMe(e);
    }); 
}

function clearJunkSPData(e){    
    enableDisableMe(e);
    $.post({
        url: "manager/settings_manager",
        contentType: 'application/json; charset=utf-8',
        data: JSON.stringify({ 
            action_type: "clear_junk_SP_data",
        }),
    }).done(function (response) {
        if(response.result == "success"){ 
            toastr.success('', 'Junk data cleared successfully!');   
        }
        else
            toastr.error('', response.error);
        enableDisableMe(e);
    }); 
}
// ---- Phase 3.42: capture webhook URL config -----------------------

(function () {
    function postSettings(payload) {
        return $.ajax({
            url: 'manager/settings_manager',
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            data: JSON.stringify(payload),
            dataType: 'json'
        });
    }
    $(function () {
        postSettings({ action_type: 'get_capture_webhook_url' }).done(function (d) {
            if (d && d.result === 'success' && typeof d.url === 'string') {
                $('#capture_webhook_url').val(d.url);
            }
        });
        $('#btn_save_capture_webhook').on('click', function () {
            var url = $('#capture_webhook_url').val().trim();
            postSettings({ action_type: 'set_capture_webhook_url', url: url })
                .done(function (d) {
                    if (d && d.result === 'success') {
                        toastr.success('', 'Webhook URL saved.');
                    } else {
                        toastr.error('', (d && d.error) || 'Save failed.');
                    }
                })
                .fail(function (xhr) {
                    toastr.error('', 'Request failed (HTTP ' + xhr.status + ').');
                });
        });

        // ---- Phase 3.52: BeEF integration settings -----------------------
        // Load fills the form once on page load; the password field stays
        // masked ("•••…") until the operator edits it, at which point the
        // save action treats only an all-bullets value as "keep existing".
        function loadBeefSettings() {
            postSettings({ action_type: 'beef_settings_load' }).done(function (d) {
                if (!d || d.result !== 'success') return;
                if (!d.configured) {
                    $('#beef_base_url').val('');
                    $('#beef_username').val('');
                    $('#beef_password').val('');
                    return;
                }
                $('#beef_base_url').val(d.base_url || '');
                $('#beef_username').val(d.username || '');
                $('#beef_password').val(d.password_masked || '');
            });
        }
        loadBeefSettings();

        $('#btn_save_beef_settings').on('click', function () {
            var payload = {
                action_type: 'beef_settings_save',
                base_url:    $('#beef_base_url').val().trim(),
                username:    $('#beef_username').val().trim(),
                password:    $('#beef_password').val()
            };
            postSettings(payload)
                .done(function (d) {
                    if (d && d.result === 'success') {
                        if (d.cleared) {
                            toastr.success('', 'BeEF credentials cleared.');
                        } else {
                            toastr.success('', 'BeEF credentials saved.');
                        }
                        loadBeefSettings();  // refresh mask
                        $('#beef_test_result').empty();
                    } else {
                        toastr.error('', (d && d.error) || 'Save failed.');
                    }
                })
                .fail(function (xhr) {
                    toastr.error('', 'Request failed (HTTP ' + xhr.status + ').');
                });
        });

        $('#btn_test_beef_connection').on('click', function () {
            $('#beef_test_result').html('<span class="text-muted">testing…</span>');
            postSettings({ action_type: 'beef_test_connection' })
                .done(function (d) {
                    if (d && d.result === 'success' && d.ok) {
                        $('#beef_test_result').html(
                            '<span class="text-success">' +
                            '<i class="fa fa-check"></i> BeEF reachable, credentials accepted.' +
                            '</span>'
                        );
                    } else {
                        var err = (d && d.error) || 'Test failed.';
                        $('#beef_test_result').html(
                            '<span class="text-warning">' +
                            '<i class="fa fa-exclamation-triangle"></i> ' + $('<div>').text(err).html() +
                            '</span>'
                        );
                    }
                })
                .fail(function (xhr) {
                    $('#beef_test_result').html(
                        '<span class="text-danger">Request failed (HTTP ' + xhr.status + ').</span>'
                    );
                });
        });

        // ---- OSINT API keys (localStorage; same keys the wizard +
        //      recipient-import already use, just discoverable here) -----
        var HUNTER_LS = 'taphish_hunter_apikey';
        var SHODAN_LS = 'taphish_shodan_key';
        function lsGet(k) { try { return localStorage.getItem(k) || ''; } catch (_) { return ''; } }
        function lsSet(k, v) {
            try {
                if (v === '') localStorage.removeItem(k);
                else localStorage.setItem(k, v);
                return true;
            } catch (_) { return false; }
        }
        $('#hunter_api_key').val(lsGet(HUNTER_LS));
        $('#shodan_api_key').val(lsGet(SHODAN_LS));
        $('#btn_save_hunter_key').on('click', function () {
            var v = $('#hunter_api_key').val().trim();
            if (lsSet(HUNTER_LS, v)) toastr.success('', v === '' ? 'Hunter.io key cleared.' : 'Hunter.io key saved.');
            else toastr.error('', 'Could not write to localStorage.');
        });
        $('#btn_save_shodan_key').on('click', function () {
            var v = $('#shodan_api_key').val().trim();
            if (lsSet(SHODAN_LS, v)) toastr.success('', v === '' ? 'Shodan key cleared.' : 'Shodan key saved.');
            else toastr.error('', 'Could not write to localStorage.');
        });

        // ---- Telegram bot alerting -----------------------------------------
        function loadTelegram() {
            postSettings({ action_type: 'telegram_settings_load' }).done(function (d) {
                if (!d || d.result !== 'success') return;
                if (!d.configured) { $('#telegram_token').val(''); $('#telegram_chat_id').val(''); return; }
                $('#telegram_token').val(d.token_masked || '');
                $('#telegram_chat_id').val(d.chat_id || '');
            });
        }
        loadTelegram();
        $('#btn_save_telegram').on('click', function () {
            postSettings({
                action_type: 'telegram_settings_save',
                token: $('#telegram_token').val(),
                chat_id: $('#telegram_chat_id').val()
            })
                .done(function (d) {
                    if (d && d.result === 'success') {
                        toastr.success('', d.cleared ? 'Telegram disabled.' : 'Telegram settings saved.');
                        loadTelegram();
                        $('#telegram_test_result').empty();
                    } else {
                        toastr.error('', (d && d.error) || 'Save failed.');
                    }
                })
                .fail(function (xhr) { toastr.error('', 'Request failed (HTTP ' + xhr.status + ').'); });
        });
        $('#btn_test_telegram').on('click', function () {
            $('#telegram_test_result').html('<span class="text-muted">sending…</span>');
            postSettings({ action_type: 'telegram_test' })
                .done(function (d) {
                    if (d && d.result === 'success' && d.ok) {
                        $('#telegram_test_result').html('<span class="text-success"><i class="fa fa-check"></i> Test message sent — check your chat.</span>');
                    } else {
                        var err = (d && d.error) || 'Test failed.';
                        $('#telegram_test_result').html('<span class="text-warning"><i class="fa fa-exclamation-triangle"></i> ' + $('<div>').text(err).html() + '</span>');
                    }
                })
                .fail(function (xhr) { $('#telegram_test_result').html('<span class="text-danger">Request failed (HTTP ' + xhr.status + ').</span>'); });
        });

        // ---- Anthropic API key (AI landing generator + future AI) ---------
        function loadAnthropic() {
            postSettings({ action_type: 'anthropic_settings_load' }).done(function (d) {
                if (!d || d.result !== 'success') return;
                if (!d.configured) { $('#anthropic_api_key').val(''); return; }
                $('#anthropic_api_key').val(d.key_masked || '');
            });
        }
        loadAnthropic();
        $('#btn_save_anthropic').on('click', function () {
            postSettings({
                action_type: 'anthropic_settings_save',
                api_key: $('#anthropic_api_key').val()
            })
                .done(function (d) {
                    if (d && d.result === 'success') {
                        if (d.cleared) toastr.success('', 'Anthropic key cleared.');
                        else if (d.noop) toastr.info('', 'No change (masked placeholder kept the existing key).');
                        else toastr.success('', 'Anthropic key saved.');
                        loadAnthropic();
                        $('#anthropic_test_result').empty();
                    } else {
                        toastr.error('', (d && d.error) || 'Save failed.');
                    }
                })
                .fail(function (xhr) { toastr.error('', 'Request failed (HTTP ' + xhr.status + ').'); });
        });
        $('#btn_test_anthropic').on('click', function () {
            $('#anthropic_test_result').html('<span class="text-muted">pinging Anthropic…</span>');
            postSettings({ action_type: 'anthropic_settings_test' })
                .done(function (d) {
                    if (d && d.result === 'success' && d.ok) {
                        var modelTag = d.model ? (' <code>' + $('<div>').text(d.model).html() + '</code>') : '';
                        $('#anthropic_test_result').html('<span class="text-success"><i class="fa fa-check"></i> Key works' + modelTag + '.</span>');
                    } else {
                        var err = (d && d.error) || 'Test failed.';
                        $('#anthropic_test_result').html('<span class="text-warning"><i class="fa fa-exclamation-triangle"></i> ' + $('<div>').text(err).html() + '</span>');
                    }
                })
                .fail(function (xhr) { $('#anthropic_test_result').html('<span class="text-danger">Request failed (HTTP ' + xhr.status + ').</span>'); });
        });

        // ---- Phase 3.57: off-host backup push destination (S3 / WebDAV) ----
        function togglePushFields() {
            var t = $('#push_type').val();
            $('#push_s3_fields').toggle(t === 's3');
            $('#push_webdav_fields').toggle(t === 'webdav');
            $('#push_actions_row').toggle(t === 's3' || t === 'webdav');
        }
        function loadPushSettings() {
            postSettings({ action_type: 'push_settings_load' }).done(function (d) {
                if (!d || d.result !== 'success') return;
                if (!d.configured || !d.cfg) { $('#push_type').val(''); togglePushFields(); return; }
                var c = d.cfg;
                $('#push_type').val(c.type || '');
                if (c.type === 's3') {
                    $('#push_bucket').val(c.bucket || '');
                    $('#push_region').val(c.region || '');
                    $('#push_access_key').val(c.access_key || '');
                    $('#push_secret_key').val('');            // never prefilled — blank on save keeps existing
                    $('#push_endpoint').val(c.endpoint || '');
                    $('#push_path_style').prop('checked', !!c.path_style);
                } else if (c.type === 'webdav') {
                    $('#push_url').val(c.url || '');
                    $('#push_user').val(c.user || '');
                    $('#push_pass').val('');                  // never prefilled
                }
                togglePushFields();
            });
        }
        $('#push_type').on('change', togglePushFields);
        loadPushSettings();
        $('#btn_save_push_settings').on('click', function () {
            var t = $('#push_type').val();
            var payload = { action_type: 'push_settings_save', type: t };
            if (t === 's3') {
                payload.bucket = $('#push_bucket').val();
                payload.region = $('#push_region').val();
                payload.access_key = $('#push_access_key').val();
                payload.secret_key = $('#push_secret_key').val();
                payload.endpoint = $('#push_endpoint').val();
                payload.path_style = $('#push_path_style').is(':checked');
            } else if (t === 'webdav') {
                payload.url = $('#push_url').val();
                payload.user = $('#push_user').val();
                payload.pass = $('#push_pass').val();
            }
            postSettings(payload)
                .done(function (d) {
                    if (d && d.result === 'success') {
                        toastr.success('', d.cleared ? 'Backup push destination cleared.' : 'Backup push destination saved.');
                        $('#push_secret_key').val('');
                        $('#push_pass').val('');
                        $('#push_test_result').empty();
                        loadPushSettings();
                    } else {
                        toastr.error('', (d && d.error) || 'Save failed.');
                    }
                })
                .fail(function (xhr) { toastr.error('', 'Request failed (HTTP ' + xhr.status + ').'); });
        });
        $('#btn_test_push_settings').on('click', function () {
            $('#push_test_result').html('<span class="text-muted">uploading test object…</span>');
            postSettings({ action_type: 'push_settings_test' })
                .done(function (d) {
                    if (d && d.result === 'success' && d.ok) {
                        $('#push_test_result').html('<span class="text-success"><i class="fa fa-check"></i> Uploaded ' + $('<div>').text(d.object || 'test object').html() + ' — destination reachable.</span>');
                    } else {
                        var err = (d && d.error) || 'Test failed.';
                        $('#push_test_result').html('<span class="text-warning"><i class="fa fa-exclamation-triangle"></i> ' + $('<div>').text(err).html() + '</span>');
                    }
                })
                .fail(function (xhr) { $('#push_test_result').html('<span class="text-danger">Request failed (HTTP ' + xhr.status + ').</span>'); });
        });

        // ---- Phase 3.60: external self-hosted landing hosts (FTPS) ----
        function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
        function clearLandingHostForm() {
            $('#lh_id').val('');
            $('#lh_label,#lh_host,#lh_username,#lh_password,#lh_base,#lh_public').val('');
            $('#lh_port').val('21');
            $('#lh_type').val('ftps');
            $('#lh_default').prop('checked', false);
            $('#lh_result,#lh_dns_out').empty();
        }
        function renderLandingHosts(profiles) {
            var $l = $('#lh_list').empty();
            if (!profiles || !profiles.length) { $l.html('<span class="text-muted">No landing hosts configured yet.</span>'); return; }
            profiles.forEach(function (p) {
                var row = $('<div class="mb-1"></div>');
                var star = p.is_default ? '<span class="badge badge-info mr-1">default</span>' : '';
                var lp = p.last_push ? ' <span class="text-success">— last push: ' + esc(p.last_push.slug || '') + ' (' + esc(String(p.last_push.uploaded || 0)) + ' files, ' + esc(p.last_push.at || '') + ')</span>' : '';
                row.append(star + '<strong>' + esc(p.label || p.host) + '</strong> '
                    + '<span class="text-muted">' + esc(p.username || '') + '@' + esc(p.host || '') + ' &rarr; ' + esc(p.public_url_base || '') + '</span>' + lp + ' ');
                $('<a href="#" class="ml-2">edit</a>').on('click', function (e) { e.preventDefault(); editLandingHost(p); }).appendTo(row);
                $('<a href="#" class="ml-2">test</a>').on('click', function (e) { e.preventDefault(); testLandingHost(p.id); }).appendTo(row);
                $('<a href="#" class="ml-2 text-danger">delete</a>').on('click', function (e) { e.preventDefault(); deleteLandingHost(p.id, p.label || p.host); }).appendTo(row);
                $l.append(row);
            });
        }
        function loadLandingHosts() {
            postSettings({ action_type: 'landing_host_list' }).done(function (d) {
                if (d && d.result === 'success') { renderLandingHosts(d.profiles || []); }
            });
        }
        function editLandingHost(p) {
            $('#lh_id').val(p.id || '');
            $('#lh_label').val(p.label || '');
            $('#lh_type').val(p.type || 'ftps');
            $('#lh_host').val(p.host || '');
            $('#lh_port').val(p.port || 21);
            $('#lh_username').val(p.username || '');
            $('#lh_password').val('');   // never prefilled — blank keeps existing
            $('#lh_base').val(p.remote_base_path || '');
            $('#lh_public').val(p.public_url_base || '');
            $('#lh_default').prop('checked', !!p.is_default);
            $('#lh_result').html('<span class="text-muted">Editing — leave password blank to keep the stored one.</span>');
        }
        function testLandingHost(id) {
            $('#lh_result').html('<span class="text-muted">testing FTPS connection…</span>');
            postSettings({ action_type: 'landing_host_test', id: id })
                .done(function (d) {
                    if (d && d.result === 'success' && d.ok) {
                        $('#lh_result').html('<span class="text-success"><i class="fa fa-check"></i> Connection + upload OK.</span>');
                    } else {
                        $('#lh_result').html('<span class="text-warning"><i class="fa fa-exclamation-triangle"></i> ' + esc((d && d.error) || 'Test failed.') + '</span>');
                    }
                })
                .fail(function (xhr) { $('#lh_result').html('<span class="text-danger">Request failed (HTTP ' + xhr.status + ').</span>'); });
        }
        function deleteLandingHost(id, label) {
            if (!window.confirm('Delete landing host "' + label + '"?')) { return; }
            postSettings({ action_type: 'landing_host_delete', id: id })
                .done(function (d) {
                    if (d && d.result === 'success') { toastr.success('', 'Landing host deleted.'); clearLandingHostForm(); loadLandingHosts(); }
                    else { toastr.error('', (d && d.error) || 'Delete failed.'); }
                })
                .fail(function (xhr) { toastr.error('', 'Request failed (HTTP ' + xhr.status + ').'); });
        }
        $('#btn_lh_new').on('click', clearLandingHostForm);
        $('#btn_lh_dns').on('click', function () {
            var host = ($('#lh_public').val() || '').replace(/^https?:\/\//, '').replace(/\/.*$/, '').trim();
            if (!host) { $('#lh_dns_out').html('<span class="text-warning">Enter the Public URL first.</span>'); return; }
            $('#lh_dns_out').html('<span class="text-muted">fetching advisory DNS records for ' + esc(host) + '…</span>');
            postSettings({ action_type: 'landing_host_dns', domain: host })
                .done(function (d) {
                    if (!d || d.result !== 'success' || !d.records) { $('#lh_dns_out').html('<span class="text-warning">' + esc((d && d.error) || 'No records.') + '</span>'); return; }
                    var lines = [];
                    (d.records || []).forEach(function (r) {
                        if (typeof r === 'string') { lines.push(r); }
                        else { lines.push([r.type, r.host || r.name || host, r.value || r.data || ''].filter(Boolean).join('  ')); }
                    });
                    $('#lh_dns_out').html('<pre class="small mb-0" style="white-space:pre-wrap">' + esc(lines.join('\n')) + '</pre>');
                })
                .fail(function (xhr) { $('#lh_dns_out').html('<span class="text-danger">Request failed (HTTP ' + xhr.status + ').</span>'); });
        });
        $('#btn_lh_save').on('click', function () {
            postSettings({
                action_type: 'landing_host_save',
                id: $('#lh_id').val(),
                label: $('#lh_label').val(),
                type: $('#lh_type').val(),
                host: $('#lh_host').val(),
                port: $('#lh_port').val(),
                username: $('#lh_username').val(),
                password: $('#lh_password').val(),
                remote_base_path: $('#lh_base').val(),
                public_url_base: $('#lh_public').val(),
                is_default: $('#lh_default').is(':checked')
            })
                .done(function (d) {
                    if (d && d.result === 'success') {
                        toastr.success('', 'Landing host saved.');
                        clearLandingHostForm();
                        loadLandingHosts();
                    } else {
                        toastr.error('', (d && d.error) || 'Save failed.');
                    }
                })
                .fail(function (xhr) { toastr.error('', 'Request failed (HTTP ' + xhr.status + ').'); });
        });
        // Quick-add: generate one landing-host profile per sub-domain from a
        // single FTP login. Saves sequentially (first = default) so the
        // default flag isn't clobbered by out-of-order responses.
        $('#btn_qlh_generate').on('click', function () {
            var domain = ($('#qlh_domain').val() || '').trim().toLowerCase()
                .replace(/^https?:\/\//, '').replace(/\/.*$/, '');
            var host = ($('#qlh_host').val() || '').trim();
            var port = $('#qlh_port').val() || '21';
            var username = ($('#qlh_username').val() || '').trim();
            var password = $('#qlh_password').val() || '';
            var prefix = ($('#qlh_prefix').val() || '').trim().replace(/^\/+|\/+$/g, '');
            var subs = [];
            ($('#qlh_subs').val() || '').split(/[\s,]+/).forEach(function (s) {
                s = s.trim().toLowerCase();
                if (s && /^[a-z0-9-]+$/.test(s) && subs.indexOf(s) === -1) subs.push(s);
            });
            if (!/^[a-z0-9.-]+\.[a-z]{2,}$/.test(domain)) { toastr.warning('', 'Enter a valid look-alike domain.'); return; }
            if (!host) { toastr.warning('', 'Enter the FTPS host.'); return; }
            if (!username) { toastr.warning('', 'Enter the FTP username.'); return; }
            if (!password) { toastr.warning('', 'Enter the FTP password.'); return; }
            if (!subs.length) { toastr.warning('', 'Enter at least one sub-domain.'); return; }

            var $btn = $('#btn_qlh_generate').prop('disabled', true);
            var done = 0, failed = [];
            $('#qlh_result').html('<div class="text-muted">Generating ' + subs.length + ' host(s)…</div>');

            function saveOne(i) {
                if (i >= subs.length) {
                    var msg = 'Created ' + done + ' of ' + subs.length + ' host(s).' +
                        (failed.length ? ' Failed: ' + failed.join(', ') + '.' : '');
                    $('#qlh_result').html('<div class="alert ' + (failed.length ? 'alert-warning' : 'alert-success') +
                        ' py-2 mb-0">' + msg + (done ? ' First sub-domain set as default.' : '') + '</div>');
                    if (done) { toastr.success('', msg); }
                    $('#qlh_password').val('');
                    $btn.prop('disabled', false);
                    loadLandingHosts();
                    return;
                }
                var fqdn = subs[i] + '.' + domain;
                postSettings({
                    action_type: 'landing_host_save',
                    id: '',
                    label: subs[i],
                    type: 'ftps',
                    host: host,
                    port: port,
                    username: username,
                    password: password,
                    remote_base_path: (prefix ? prefix + '/' : '') + fqdn,
                    public_url_base: 'https://' + fqdn,
                    is_default: (i === 0)
                })
                    .done(function (d) { if (d && d.result === 'success') { done++; } else { failed.push(subs[i]); } })
                    .fail(function () { failed.push(subs[i]); })
                    .always(function () { saveOne(i + 1); });
            }
            saveOne(0);
        });

        loadLandingHosts();
    });
})();
