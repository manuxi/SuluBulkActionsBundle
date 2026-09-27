import React from 'react';
import {action, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {AbstractListToolbarAction} from 'sulu-admin-bundle/views';
import {Requester} from 'sulu-admin-bundle/services';
import {Dialog} from 'sulu-admin-bundle/components';

const PREFIX = 'sulu_bulk_actions';

/**
 * One dropdown in the toolbar of a list with the actions given in the option "actions"
 * (publish, unpublish, delete). It works on the selected rows and asks for confirmation first.
 */
export default class BulkActionsToolbarAction extends AbstractListToolbarAction {
    @observable pendingAction = undefined;
    @observable loading = false;
    @observable errorMessage = undefined;

    getActions() {
        const {actions = []} = this.options;

        return Array.isArray(actions) ? actions : [];
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

    @action handleErrorClose = () => {
        this.errorMessage = undefined;
    };

    @action execute(name) {
        const ids = this.listStore.selectionIds;
        const locale = this.router.attributes.locale;

        this.loading = true;

        Requester.post(
            `/admin/api/bulk-actions/${this.listStore.resourceKey}/${name}?locale=${locale}`,
            {ids}
        ).then(action(() => {
            this.listStore.clearSelection();
            this.listStore.reload();
        })).catch(action((response) => {
            const fallback = translate(`${PREFIX}.error`);

            if (response && typeof response.json === 'function') {
                response.json()
                    .then(action((data) => {
                        this.errorMessage = data && data.error ? data.error : fallback;
                    }))
                    .catch(action(() => {
                        this.errorMessage = fallback;
                    }));
            } else {
                this.errorMessage = fallback;
            }

            this.listStore.reload();
        })).finally(action(() => {
            this.loading = false;
        }));
    }

    getNode() {
        const count = this.listStore.selectionIds.length;
        const name = this.pendingAction;

        return (
            <React.Fragment>
                <Dialog
                    cancelText={translate('sulu_admin.cancel')}
                    confirmLoading={this.loading}
                    confirmText={translate('sulu_admin.ok')}
                    onCancel={this.handleCancel}
                    onConfirm={this.handleConfirm}
                    open={!!name}
                    title={translate(`${PREFIX}.${name || 'publish'}_confirm_title`)}
                >
                    {translate(`${PREFIX}.${name || 'publish'}_confirm_text`, {count})}
                </Dialog>
                <Dialog
                    cancelText={translate('sulu_admin.ok')}
                    onCancel={this.handleErrorClose}
                    onConfirm={this.handleErrorClose}
                    open={!!this.errorMessage}
                    title={translate(`${PREFIX}.error_title`)}
                >
                    {this.errorMessage}
                </Dialog>
            </React.Fragment>
        );
    }
}
