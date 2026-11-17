/**
 * Budget request form helpers (Project Leader): status labels, the values
 * an "Edit and resubmit" form starts from, and the request body.
 */

/** Row status -> label. A legacy "rejected" is a returned request. */
export const REQUEST_STATUS = {
  pending: 'Pending',
  approved: 'Approved',
  returned: 'Returned',
  withdrawn: 'Withdrawn',
  rejected: 'Returned',
};

export function statusLabel(status) {
  return REQUEST_STATUS[status] ?? status;
}

const text = (value) => (value == null ? '' : String(value));

/** Form values for a new request (`initial` from ?new=&tranche=) or a returned one (`edit` from the row). */
export function initialForm(edit = null, initial = {}) {
  if (edit) {
    return {
      type: edit.type ?? 'fund_release',
      trancheId: text(edit.project_tranche_id),
      fromId: text(edit.from_lib_category_id),
      toId: text(edit.to_lib_category_id),
      amount: text(edit.amount),
      title: edit.type === 'liquidation' ? text(edit.title) : '',
      justification: text(edit.justification),
    };
  }
  return {
    type: initial.type ?? 'fund_release',
    trancheId: text(initial.trancheId),
    fromId: '',
    toId: '',
    amount: '',
    title: '',
    justification: '',
  };
}

/** Request body for the form's type (the type itself is added on create only). */
export function requestFields(form) {
  const fields = { justification: form.justification.trim() };
  if (form.type === 'fund_release') fields.project_tranche_id = Number(form.trancheId);
  if (form.type === 'realignment') {
    Object.assign(fields, { from_lib_category_id: Number(form.fromId), to_lib_category_id: Number(form.toId), amount: Number(form.amount) });
  }
  if (form.type === 'liquidation') {
    Object.assign(fields, { amount: Number(form.amount), title: form.title.trim(), project_tranche_id: form.trancheId || '' });
  }
  fields.document_type = form.type === 'liquidation' ? 'receipt' : 'other';
  return fields;
}

/** Tranche ids held by another pending fund release request (`exceptId` is the request being edited). */
export function pendingTrancheIds(requests, exceptId = null) {
  return new Set((requests ?? [])
    .filter((row) => row.type === 'fund_release' && row.status === 'pending' && String(row.id) !== String(exceptId ?? ''))
    .map((row) => String(row.project_tranche_id)));
}

/** Documents the request will have: kept existing ones plus new files. */
export function documentsAfter(existing, removeIds, newFiles) {
  const removed = new Set((removeIds ?? []).map(String));
  return (existing ?? []).filter((doc) => !removed.has(String(doc.id))).length + (newFiles?.length ?? 0);
}
