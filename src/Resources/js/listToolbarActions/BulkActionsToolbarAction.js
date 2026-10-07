import React from 'react';
import {action, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {AbstractListToolbarAction} from 'sulu-admin-bundle/views';
import {Requester} from 'sulu-admin-bundle/services';
import {Checkbox, Dialog, Form, SingleSelect} from 'sulu-admin-bundle/components';
import {userStore} from 'sulu-admin-bundle/stores';

const PREFIX = 'sulu_bulk_actions';
const COPY_LOCALE = 'copy_locale';
const MAX_LISTED_ENTRIES = 5;

/**
 * Name of a content locale in the language of the user ("en" -> "Englisch"), the code itself if the browser
 * does not know it.
 */
function languageName(locale) {
    try {
        const name = new Intl.DisplayNames([userStore.systemLocale], {type: 'language'})
            .of(locale.replace('_', '-'));

        return name || locale;
    } catch (e) {
        return locale;
    }
}

/**
 * Entries of a result section: "„Title“: message", at most MAX_LISTED_ENTRIES and a hint for the rest.
 */
function listEntries(entries) {
    return {
        items: entries.slice(0, MAX_LISTED_ENTRIES).map(({id, title, message}) => ({
            key: id,
            label: title ? `„${title}“` : id,
            message,
        })),
        more: Math.max(0, entries.length - MAX_LISTED_ENTRIES),
    };
}

/**
 * One dropdown in the toolbar of a list with the actions given in the option "actions"
 * (publish, unpublish, copy_locale, delete). It works on the selected rows and asks for confirmation first;
 * copy_locale asks for the source and target locale instead.
 */
export default class BulkActionsToolbarAction extends AbstractListToolbarAction {
    @observable pendingAction = undefined;
    @observable loading = false;
    @observable result = undefined;
    @observable sourceLocale = undefined;
    @observable targetLocale = undefined;
    @observable overwrite = false;

    getActions() {
        const {actions = []} = this.options;

        if (!Array.isArray(actions)) {
            return [];
        }

        // copying needs a second locale to copy into
        return actions.filter((name) => name !== COPY_LOCALE || this.getLocales().length > 1);
    }

    getLocales() {
        return Array.isArray(this.locales) ? this.locales : [];
    }

    getToolbarItemConfig() {
        const hasSelection = this.listStore.selectionIds.length > 0;

        return {
            type: 'dropdown',
            label: translate(`${PREFIX}.actions`),
            icon: 'su-more',
            disabled: this.loading,
            loading: this.loading,
            options: this.getActions().map((name) => ({
                label: translate(`${PREFIX}.${name}`),
                disabled: !hasSelection,
                onClick: () => this.request(name),
            })),
        };
    }

    @action request(name) {
        if (name === COPY_LOCALE) {
            const locales = this.getLocales();
            const current = this.router.attributes.locale;

            this.sourceLocale = locales.includes(current) ? current : locales[0];
            this.targetLocale = locales.find((locale) => locale !== this.sourceLocale);
            this.overwrite = false;
        }

        this.pendingAction = name;
    }

    @action handleCancel = () => {
        this.pendingAction = undefined;
    };

    @action handleConfirm = () => {
        const name = this.pendingAction;
        this.pendingAction = undefined;
        this.execute(name);
    };

    @action handleSourceLocaleChange = (locale) => {
        this.sourceLocale = locale;

        if (this.targetLocale === locale) {
            this.targetLocale = this.getLocales().find((candidate) => candidate !== locale);
        }
    };

    @action handleTargetLocaleChange = (locale) => {
        this.targetLocale = locale;
    };

    @action handleOverwriteChange = (checked) => {
        this.overwrite = checked;
    };

    @action handleResultClose = () => {
        this.result = undefined;
    };

    /**
     * Explains what happened with the selected entries; nothing to say if everything was done.
     */
    @action showResult(name, locale, data) {
        const {done = 0, missing = 0, denied = 0, failed = 0, failures = []} = data;

        if (missing === 0 && denied === 0 && failed === 0) {
            return;
        }

        const sections = [];
        if (done > 0) {
            sections.push({text: translate(`${PREFIX}.${name}_done`, {count: done})});
        }
        if (missing > 0) {
            sections.push({
                text: translate(`${PREFIX}.missing_locale`, {
                    count: missing,
                    language: languageName(data.locale || locale),
                }),
            });
        }
        if (denied > 0) {
            sections.push({text: translate(`${PREFIX}.denied`, {count: denied})});
        }
        if (failed > 0) {
            sections.push({text: translate(`${PREFIX}.failed`, {count: failed}), ...listEntries(failures)});
        }

        this.result = {
            title: translate(done > 0 ? `${PREFIX}.result_title_partial` : `${PREFIX}.result_title_none`),
            sections,
        };
    }

    /**
     * Always explains the result of a copy: also a full success needs the hint that the copies are drafts.
     */
    @action showCopyResult(data) {
        const {done = 0, missing = 0, existing = 0, denied = 0, failed = 0, skipped = [], failures = []} = data;
        const source = languageName(data.locale || this.sourceLocale);
        const target = languageName(data.targetLocale || this.targetLocale);
        const skippedFor = (reason) => skipped.filter((entry) => entry.reason === reason);

        const sections = [];
        if (done > 0) {
            sections.push({text: translate(`${PREFIX}.copy_locale_done`, {count: done, language: target})});
        }
        if (missing > 0) {
            sections.push({
                text: translate(`${PREFIX}.copy_locale_missing`, {count: missing, language: source}),
                ...listEntries(skippedFor('missing')),
            });
        }
        if (existing > 0) {
            sections.push({
                text: translate(`${PREFIX}.copy_locale_existing`, {count: existing, language: target}),
                ...listEntries(skippedFor('existing')),
            });
        }
        if (denied > 0) {
            sections.push({text: translate(`${PREFIX}.denied`, {count: denied})});
        }
        if (failed > 0) {
            sections.push({text: translate(`${PREFIX}.failed`, {count: failed}), ...listEntries(failures)});
        }

        const complete = missing === 0 && existing === 0 && denied === 0 && failed === 0;

        this.result = {
            title: translate(complete
                ? `${PREFIX}.copy_locale_result_title`
                : (done > 0 ? `${PREFIX}.result_title_partial` : `${PREFIX}.result_title_none`)),
            sections,
        };
    }

    @action showError(message) {
        this.result = {
            title: translate(`${PREFIX}.error_title`),
            sections: [{text: message || translate(`${PREFIX}.error`)}],
        };
    }

    @action execute(name) {
        const ids = this.listStore.selectionIds;
        const locale = name === COPY_LOCALE ? this.sourceLocale : this.router.attributes.locale;
        const body = name === COPY_LOCALE
            ? {ids, sourceLocale: this.sourceLocale, targetLocale: this.targetLocale, overwrite: this.overwrite}
            : {ids};
        const show = action((data) => {
            if (name === COPY_LOCALE) {
                this.showCopyResult(data);
            } else {
                this.showResult(name, locale, data);
            }
        });

        this.loading = true;

        Requester.post(
            `/admin/api/bulk-actions/${this.listStore.resourceKey}/${name}?locale=${locale}`,
            body
        ).then(action((data) => {
            this.listStore.clearSelection();
            this.listStore.reload();

            if (data) {
                show(data);
            }
        })).catch(action((response) => {
            if (response && typeof response.json === 'function') {
                response.json()
                    .then(action((data) => {
                        // a result with counts (nothing was done) or an error of the request itself
                        if (data && typeof data.done === 'number') {
                            this.listStore.clearSelection();
                            show(data);
                        } else {
                            this.showError(data && data.error);
                        }
                    }))
                    .catch(action(() => {
                        this.showError();
                    }));
            } else {
                this.showError();
            }

            this.listStore.reload();
        })).finally(action(() => {
            this.loading = false;
        }));
    }

    renderLocaleSelect(value, onChange, exclude) {
        return (
            <SingleSelect onChange={onChange} value={value}>
                {this.getLocales().filter((locale) => locale !== exclude).map((locale) => (
                    <SingleSelect.Option key={locale} value={locale}>
                        {`${languageName(locale)} (${locale})`}
                    </SingleSelect.Option>
                ))}
            </SingleSelect>
        );
    }

    renderCopyLocaleDialog(count) {
        return (
            <Dialog
                cancelText={translate('sulu_admin.cancel')}
                confirmDisabled={!this.sourceLocale || !this.targetLocale || this.sourceLocale === this.targetLocale}
                confirmLoading={this.loading}
                confirmText={translate('sulu_admin.ok')}
                onCancel={this.handleCancel}
                onConfirm={this.handleConfirm}
                open={this.pendingAction === COPY_LOCALE}
                title={translate(`${PREFIX}.copy_locale_confirm_title`)}
            >
                <p>{translate(`${PREFIX}.copy_locale_confirm_text`, {count})}</p>
                <Form>
                    <Form.Field colSpan={6} label={translate(`${PREFIX}.copy_locale_source`)}>
                        {this.renderLocaleSelect(this.sourceLocale, this.handleSourceLocaleChange)}
                    </Form.Field>
                    <Form.Field colSpan={6} label={translate(`${PREFIX}.copy_locale_target`)}>
                        {this.renderLocaleSelect(this.targetLocale, this.handleTargetLocaleChange, this.sourceLocale)}
                    </Form.Field>
                    <Form.Field
                        colSpan={12}
                        description={translate(`${PREFIX}.copy_locale_overwrite_info`)}
                    >
                        <Checkbox checked={this.overwrite} onChange={this.handleOverwriteChange}>
                            {translate(`${PREFIX}.copy_locale_overwrite`)}
                        </Checkbox>
                    </Form.Field>
                </Form>
            </Dialog>
        );
    }

    getNode() {
        const count = this.listStore.selectionIds.length;
        const name = this.pendingAction;
        const confirmName = name && name !== COPY_LOCALE ? name : 'publish';
        const {result} = this;

        return (
            <React.Fragment>
                <Dialog
                    cancelText={translate('sulu_admin.cancel')}
                    confirmLoading={this.loading}
                    confirmText={translate('sulu_admin.ok')}
                    onCancel={this.handleCancel}
                    onConfirm={this.handleConfirm}
                    open={!!name && name !== COPY_LOCALE}
                    title={translate(`${PREFIX}.${confirmName}_confirm_title`)}
                >
                    {translate(`${PREFIX}.${confirmName}_confirm_text`, {count})}
                </Dialog>
                {this.renderCopyLocaleDialog(count)}
                <Dialog
                    confirmText={translate('sulu_admin.ok')}
                    onCancel={this.handleResultClose}
                    onConfirm={this.handleResultClose}
                    open={!!result}
                    title={result ? result.title : ''}
                >
                    {result && result.sections.map(({text, items = [], more = 0}) => (
                        <React.Fragment key={text}>
                            <p>{text}</p>
                            {items.length > 0 &&
                                <ul>
                                    {items.map(({key, label, message}) => (
                                        <li key={key}>{message ? `${label}: ${message}` : label}</li>
                                    ))}
                                    {more > 0 &&
                                        <li>{translate(`${PREFIX}.more_failures`, {count: more})}</li>
                                    }
                                </ul>
                            }
                        </React.Fragment>
                    ))}
                </Dialog>
            </React.Fragment>
        );
    }
}
