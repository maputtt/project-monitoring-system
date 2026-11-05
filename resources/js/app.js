import Alpine from 'alpinejs';
import formModal from './form-modal';
import invoicePreview from './invoice-preview';
import projectPreview, { adjustPreview, billingPreview } from './project-preview';

window.Alpine = Alpine;
Alpine.data('formModal', formModal);
Alpine.data('invoicePreview', invoicePreview);
Alpine.data('projectPreview', projectPreview);
Alpine.data('adjustPreview', adjustPreview);
Alpine.data('billingPreview', billingPreview);
Alpine.start();

// Browser Back after logout must not show a bfcache-restored authenticated page.
window.addEventListener('pageshow', (e) => { if (e.persisted) location.reload(); });
