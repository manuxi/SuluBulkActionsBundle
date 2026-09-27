import {listToolbarActionRegistry} from 'sulu-admin-bundle/views';
import BulkActionsToolbarAction from './listToolbarActions/BulkActionsToolbarAction';

listToolbarActionRegistry.add('sulu_bulk_actions.actions', BulkActionsToolbarAction);
