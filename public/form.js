/**
 * ADR 009 rule 6: every resource write is submitted by this script, never natively, because the
 * only per-status HTML error channel API Platform exposes (`error_formats` carrying `html`) is
 * status-agnostic and would send a validation failure to an error page along with the read failures
 * it exists for. One function for the whole application; no card may fork it (rule 6, and F3's
 * plan for this file).
 *
 * THE OPT-IN IS THE PRESENCE OF `data-action`, AND THERE IS NO SECOND MARKER. A form this script
 * owns carries `data-action` and `data-method`, and `method="dialog"` in place of a native `action`
 * and `method`. That is rule 6's widened clause, and it replaces the earlier `data-write-form`
 * opt-in, which sat beside a native `action="/members" method="post"` and therefore only prevented
 * a native submit while the script was present.
 *
 * `method="dialog"` RATHER THAN NO `method` AT ALL, AND THE PASSWORD IS WHY. A form with no
 * `action` and no `method` submits a `GET` to the page it is already on, which is harmless until a
 * form grows a password: that `GET` puts it in a query string, and therefore in the access log, the
 * history and any `Referer`. A rule that moves a password from a `415` into a URL is worse than the
 * bug it replaced. `method="dialog"` on a form with no ancestor `<dialog>` still fires the `submit`
 * event, which is what the listener below is waiting for, and then does nothing at all: the HTML
 * form submission algorithm fires `submit` before it reads the method, and the dialog state with no
 * ancestor `<dialog>` returns without navigating. Verified in Blink with the listener deliberately
 * not calling `preventDefault()`: the event fires and the URL does not change.
 *
 * Two markers for one thing is also what let a form miss this script entirely: the catalogue's
 * correction form was written with `data-method="post"` and no `data-write-form`, so it submitted
 * natively every time. One attribute cannot be half-spelled.
 *
 * The absence of `data-action` is what leaves the login form alone. It is received by the
 * firewall rather than by a resource, so it stays native and needs no marker to say so.
 *
 * The method is read rather than assumed, which is what lets one function serve the `POST`s the
 * board has today and the `PATCH`, `PUT` and `DELETE` writes a form cannot express at all. A form
 * that declares no `data-method` is a `POST`.
 *
 * `data-precondition` IS THE THIRD AND LAST ATTRIBUTE, AND IT IS A URL. ADR 009 rule 6 says
 * `data-on-success` holds "a URL and nothing else, never a function name, never a selector, never a
 * hook", and gives the reason the rule can take it "without the script growing options": an
 * attribute holding a URL is the same kind of thing `data-action` already is. This is a URL. The
 * two phrases above are quoted from `templates/membership/register.html.twig`, which is where rule 6
 * is written out in full; the ADR states the rule and the page states the limit. It buys the one capability a form cannot have at all and a script
 * can: a REQUEST HEADER on a conditional write.
 *
 * `PATCH /subscriptions/{subscriptionId}` is the only conditional write in this API and it REQUIRES
 * `If-Match`, answering `428 Precondition Required` to a request without one, for the reason
 * `Ui/Api/Resources/Membership/SubscriptionPlanResource` argues at length: a guarantee an unaware
 * client opts out of by doing nothing is not a guarantee. So the page has to send a validator, and
 * the page it is drawn from is a projection that is forbidden from publishing one. The attribute
 * names the read that can: this script `GET`s it, takes `version` off the body, and sends that as
 * `If-Match` on the write.
 *
 * THE VALIDATOR IS READ FROM THE BODY AND NOT FROM AN `ETag` RESPONSE HEADER, which is a decision
 * rather than an oversight. This API hands the validator over as a `version` FIELD on every write
 * already: `BadgeClubMembershipResource` documents its own as "the validator to send back as
 * `If-Match`", and `SubscriptionPlanResource` answers with the new one. Reading the same field from
 * the same shape on a read is one rule for the whole surface.
 *
 * The alternative was to set an `ETag` response header and read it with `response.headers.get`, and
 * it loses twice. **A cross-origin reader cannot see `ETag` at all**: the Fetch Standard's
 * CORS-safelisted response-header names are `Cache-Control`, `Content-Language`, `Content-Length`,
 * `Content-Type`, `Expires`, `Last-Modified` and `Pragma`, and anything else is invisible to script
 * until the server names it in `Access-Control-Expose-Headers`. A body field is already there and
 * needs nobody's permission. And measured in this tree, the header does not exist to be read:
 * `grep -rn "setEtag\|'ETag'" src/ config/` is empty, no file under `vendor/api-platform/` mentions
 * `ETag`, and there is no CORS layer at all (no `nelmio/cors-bundle` in `composer.json`, no
 * `Access-Control-Expose-Headers` anywhere in `config/`). So that option is two new mechanisms to
 * publish a token the body is already carrying.
 *
 * A FAILED PRECONDITION READ IS A FAILED WRITE, AND NEVER AN UNCONDITIONAL ONE. If the `GET` is
 * refused, or comes back without a `version`, the write is not sent and the refusal is painted the
 * way a refused write is. Falling back to writing without the header would turn a required
 * precondition into an optional one, in the script, for every form that ever carries this attribute,
 * which is the whole of what the `428` exists to prevent.
 */
document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    var action = form.getAttribute('data-action');
    if (!action) {
        return;
    }

    event.preventDefault();
    clearFormFeedback(form);

    // A NAME ENDING IN `[]` IS A ROW OF CONTROLS AND ITS VALUE IS A LIST, ALWAYS, INCLUDING WHEN
    // THE LIST IS EMPTY. membership/05's preference chips are the first list payload on the board and
    // they are what found this: a `FormData` yields `colors[]` once per checked chip, the one line
    // build this replaces kept the last one as a string under the key `colors[]`, and the resource
    // never saw a `colors` at all. Measured, that form sent `{"colors[]":"purple","motifs[]":"stars"}`
    // and was answered a `422` about a field it had not mentioned.
    //
    // The rows are seeded before the entries are read, because an empty row is "no constraint"
    // rather than an absent field: `{"colors":[]}` clears a preference and `{}` leaves the
    // resource's own default standing, and a `PUT` that replaces a whole value object is exactly
    // where that difference bites.
    var payload = {};
    form.querySelectorAll('[name$="[]"]').forEach(function (control) {
        payload[control.name.slice(0, -2)] = [];
    });
    new FormData(form).forEach(function (value, key) {
        if ('[]' === key.slice(-2)) {
            var name = key.slice(0, -2);
            (payload[name] = payload[name] || []).push(value);
        } else {
            payload[key] = value;
        }
    });
    // A LONE CHECKBOX IS A BOOLEAN AND AN UNCHECKED ONE IS `false`, NOT SILENCE. A `FormData` omits
    // an unchecked box entirely and spells a checked one `"1"`, so a boolean resource property
    // answered `This value should be of type bool.` when the switch was on and silently restored
    // its own default when it was off. The control is read rather than the entry, because the
    // control is the only place where "off" and "absent" are still different things.
    form.querySelectorAll('input[type="checkbox"]:not([name$="[]"])').forEach(function (box) {
        payload[box.name] = box.checked;
    });

    var method = (form.getAttribute('data-method') || 'POST').toUpperCase();

    // A FORM WITH NO `data-precondition` IS EVERY FORM ON THE BOARD EXCEPT THE PLAN SWITCH, so the
    // read is not a cost every write pays: the branch is on the attribute and a form that does not
    // carry it sends exactly the one request it always sent.
    //
    // `readProblem` is reused for a `200` and its name is about its first caller rather than about
    // its contract, which is "parse this body or answer an empty object". That is what both arms
    // need here: a precondition read that comes back as HTML from a proxy must land in the refusal
    // arm rather than reject and leave the form looking dead, exactly as a `500` on a write does.
    //
    // `!body.version` covers the three ways this read can fail to produce a validator and treats
    // them identically, because the page can do nothing different about any of them: a non-JSON
    // body, a representation without the field, and a `version` of `null`, which is what the item
    // publishes when the billing provider minted no ETag. A conditional write is impossible in all
    // three.
    var precondition = form.getAttribute('data-precondition');
    if (!precondition) {
        submitWrite(form, action, method, payload, null);
        return;
    }

    fetch(precondition, {
        headers: {
            Accept: 'application/ld+json',
        },
    }).then(function (response) {
        return readProblem(response).then(function (body) {
            if (!response.ok || !body.version) {
                // The honest limit of this line, stated rather than papered over: a refused read
                // carries a problem document and `paintNotice` draws the server's own sentence, but
                // a `200` with no `version` carries no sentence anywhere, so the notice falls back
                // to the status line. Writing one here is the thing this file must not do: ADR 007
                // puts every sentence in `translations/failures+intl-icu.en.xlf` and a string in
                // this file would be a second catalogue `app:messages` cannot walk.
                paintNotice(form, body, response);
                return;
            }

            submitWrite(form, action, method, payload, body.version);
        });
    });
});

/**
 * The write itself, extracted so that the precondition read above has something to call and so that
 * there is still exactly one `fetch` sending a resource write. It takes the validator as an argument
 * rather than reading the attribute again, which is what keeps "the header is set if and only if a
 * validator was read" true by construction instead of by two call sites agreeing.
 */
function submitWrite(form, action, method, payload, validator) {
    var headers = {
        // A `PATCH` BODY IS A PATCH DOCUMENT AND NOT A REPRESENTATION, so its media type is
        // read off the method rather than fixed. `config/packages/api_platform.php` declares
        // `patch_formats: {json: ['application/merge-patch+json']}`, which ADR 005 put back
        // once there was a `PATCH` to need it, and a body labelled `application/ld+json` on one
        // answers `415` from `ApiPlatform\State\Provider\ContentNegotiationProvider` naming
        // the single type it will take. Measured against `PATCH /members/{memberId}` before
        // this line existed, through the session firewall, with exactly the request this
        // function sends.
        //
        // The other available fix was a second entry in `patch_formats`, and it is the wrong
        // one: it would publish `application/ld+json` as a patch format on every present and
        // future `PATCH`, in a document CLAUDE.md calls a deliverable, in order to say
        // something untrue about what a patch body is. This is derived rather than declared,
        // so no form has to know.
        'Content-Type': 'PATCH' === method ? 'application/merge-patch+json' : 'application/ld+json',
        Accept: 'application/ld+json',
    };

    // THE ONLY CONDITIONAL HEADER THIS SCRIPT SENDS, AND IT IS ABSENT RATHER THAN EMPTY WHEN THERE
    // IS NO VALIDATOR. An `If-Match: ""` is a well-formed field value that matches nothing, so it
    // would turn the `428` the server owes a caller with no validator into a `412` this script
    // invented, and a `412` tells the caller to re-read, which is the one thing that would not
    // help. The object is built first and the field added afterwards, rather than written inline
    // with a ternary, so that "no validator" is spelled as an absent key and never as a value the
    // `Headers` constructor has to make something of.
    if (validator) {
        headers['If-Match'] = validator;
    }

    return fetch(action, {
        method: method,
        headers: headers,
        body: JSON.stringify(payload),
    }).then(function (response) {
        // A `201` and a `202` are both a `Location` the script follows; watching what it lands on
        // is that page's business, by rule 12. The script learns no third behaviour for the
        // erasure's `202`, which is the branch rule 6 says this file must not grow.
        //
        // A FORM MAY SAY WHERE THE READER GOES INSTEAD, WITH `data-on-success`, AND THAT IS THE
        // ONLY OPTION THIS SCRIPT WILL EVER TAKE. It exists because registration needs it and
        // nothing on the server can supply it: the `201` correctly names the created member, but a
        // reader who has just registered has no session yet, so following that `Location` lands
        // them on a refusal. The limit is exactly that: `data-on-success` is a URL, never a
        // function name, never a selector, never a hook. If you came here to add a callback, a
        // per-form hook or a registry, that is the thing ADR 009 rule 6 refuses, and the way to
        // have it is to reopen the ADR rather than to widen this line.
        //
        // EVERY 2XX IS A SUCCESS, AND ONLY A `201` OR A `202` CARRIES A `Location`. This used to
        // read `status === 201 || status === 202`, which sent every other success down the refusal
        // path below: `PATCH /members/{memberId}` answers `200` with the member as it now stands, a
        // successful JSON-LD body has neither `detail` nor `title`, and HTTP/2 has no reason
        // phrase, so `paintNotice()` drew an empty red box over a rename that had worked.
        // `PUT /members/{memberId}/matching-preferences` answers `200` too.
        if (response.ok) {
            var destination = form.getAttribute('data-on-success')
                || (201 === response.status || 202 === response.status ? response.headers.get('Location') : null);
            if (destination) {
                window.location.assign(destination);
            }
            return;
        }

        return readProblem(response).then(function (problem) {
            if (response.status === 422) {
                paintViolations(form, problem);
                return;
            }

            // A `409` IS NEVER PAINTED ONTO A FIELD. ADR 005 makes `ConflictThrowable`
            // non-collectable: a conflict is a fact about state rather than about a field, it
            // arrives alone by construction, and its problem document carries no `violations`
            // array and so no `propertyPath` to aim at. Verified against a live `POST /members`
            // with a taken handle, which answers `title`, `detail`, `status`, `instance` and
            // `type` and nothing else. So it is a page-level notice and every typed value stays
            // where it is.
            //
            // Every other refusal lands here too, and that is deliberate rather than a fallback:
            // `422` is the only status in this tree that has anything field-shaped to say.
            paintNotice(form, problem, response);
        });
    });
}

/**
 * The body of a refusal, or an empty problem when there is not one. A `500` behind a proxy, or any
 * answer that is not JSON, would otherwise reject here and leave the form looking dead; the notice
 * falls back to the status line the response already carries.
 *
 * The precondition read calls it on a `200` as well, which the name does not suggest and the
 * contract does: "parse this body, or answer an empty object". The name is kept because the other
 * two call sites are refusals and because an empty object is the right answer to a precondition read
 * that came back as something other than JSON; what matters is that nothing in this file ever calls
 * `response.json()`, whose rejection is the failure mode this function exists to remove.
 */
function readProblem(response) {
    return response.text().then(function (body) {
        try {
            var parsed = JSON.parse(body);

            // `JSON.parse` answers `null`, a number or a string for a body that is valid JSON and
            // is not an object, and every caller below reads a property off what comes back. A
            // `200` whose body is the four characters `null` would otherwise throw a TypeError
            // inside the `then`, which a promise swallows into a rejected chain and the page
            // reports as nothing at all. An empty object is the honest answer: there was a body
            // and it carried none of the members this file knows how to read.
            return parsed && 'object' === typeof parsed ? parsed : {};
        } catch (error) {
            return {};
        }
    });
}

/**
 * ADR 005 made every `422` in this tree carry one shape, whichever of the two validation designs
 * produced it, so this function needs to know nothing about which one did: option A emits Symfony's
 * constraint UUIDs as the `code` and option B the declared codes, and the markup below is what
 * `templates/components/_field.html.twig` already emits for a violation rendered server-side.
 *
 * A violation naming a field this form does not hold is dropped onto the page-level notice rather
 * than swallowed, so a `propertyPath` that drifts from a field name is visible instead of silent.
 */
function paintViolations(form, problem) {
    var violations = Array.isArray(problem.violations) ? problem.violations : [];
    if (violations.length === 0) {
        paintNotice(form, problem, null);
        return;
    }

    var unplaceable = [];
    violations.forEach(function (violation) {
        if (!paintViolation(form, violation)) {
            unplaceable.push(violation);
        }
    });

    unplaceable.forEach(function (violation) {
        insertNotice(form, violation.message, violation.code);
    });
}

function paintViolation(form, violation) {
    var field = form.querySelector('[name="' + violation.propertyPath + '"]');
    var wrapper = field ? field.closest('.field') : null;
    if (!wrapper) {
        return false;
    }

    wrapper.classList.add('field--invalid');

    var count = wrapper.querySelectorAll('.violation').length + 1;
    var id = violation.propertyPath + '-violation-' + count;

    var span = document.createElement('span');
    span.className = 'violation body-sm';
    span.id = id;

    var message = document.createElement('span');
    message.textContent = violation.message;
    var code = document.createElement('span');
    code.className = 'violation__code code';
    code.textContent = violation.code;
    span.appendChild(message);
    span.appendChild(code);
    wrapper.appendChild(span);

    if (field) {
        field.setAttribute('aria-invalid', 'true');
        var describedBy = field.getAttribute('aria-describedby');
        field.setAttribute('aria-describedby', describedBy ? describedBy + ' ' + id : id);
    }

    return true;
}

/**
 * The page-level notice, in `_notice.html.twig`'s markup and with its `danger` tone.
 *
 * Every word in it came off the wire. `detail` and `title` are RFC 9457 members the server already
 * rendered through `Shared/Infrastructure/Translation/FailureMessages`, which is ADR 007's one home for
 * English, and `statusText` is HTTP's own. This file invents no sentence, because a sentence
 * written here would be a second catalogue that `app:messages` cannot walk.
 */
function paintNotice(form, problem, response) {
    var sentence = problem.detail || problem.title || (response ? response.statusText : '');
    insertNotice(form, sentence, null);
}

function insertNotice(form, sentence, detail) {
    var notice = document.createElement('div');
    notice.className = 'notice notice--danger';
    notice.setAttribute('data-form-notice', '');

    var paragraph = document.createElement('p');
    paragraph.className = 'body';
    paragraph.style.margin = '0';
    paragraph.textContent = sentence;
    notice.appendChild(paragraph);

    if (detail) {
        var line = document.createElement('p');
        line.className = 'meta';
        line.style.margin = '2px 0 0';
        line.textContent = detail;
        notice.appendChild(line);
    }

    form.insertBefore(notice, form.firstChild);
}

function clearFormFeedback(form) {
    form.querySelectorAll('[data-form-notice]').forEach(function (node) {
        node.remove();
    });
    form.querySelectorAll('.field--invalid').forEach(function (field) {
        field.classList.remove('field--invalid');
    });
    form.querySelectorAll('.violation').forEach(function (node) {
        node.remove();
    });
    form.querySelectorAll('[aria-invalid]').forEach(function (field) {
        field.removeAttribute('aria-invalid');
        field.removeAttribute('aria-describedby');
    });
}
