import { Fragment } from 'react';
import ProgramPill from '../program/ProgramPill';
import { RowMenu } from '../ActionMenu';
import { peso } from '../money';
import { lineItemActions, menuOptions } from '../../lib/projectRowActions';
import { sourceLabels } from '../../lib/fundingSource';
import Tip from '../Tip';

/** Verified spending under one Form B node (a group, cost type, section…). */
function spentOf(node) {
  const items = [
    ...(node.items ?? []),
    ...(node.subgroups ?? []).flatMap((sub) => sub.items ?? []),
  ];
  const nested = [...(node.groups ?? []), ...(node.cost_types ?? []), ...(node.sections ?? [])];
  const cents = items.reduce((sum, item) => sum + Math.round((Number(item.spent) || 0) * 100), 0)
    + nested.reduce((sum, child) => sum + Math.round(spentOf(child) * 100), 0);
  return cents / 100;
}

function RemainingCell({ value }) {
  return (
    <td className={`num${value < 0 ? ' ld-neg' : ''}`}>
      {value < 0 ? <span aria-hidden="true">⚠ </span> : null}{peso(value)}
      {value < 0 && <span className="pa-sr-only"> (over budget)</span>}
    </td>
  );
}

/**
 * A sub-total row: counterpart, funder and total. With `remaining`, also the
 * verified spending and the balance under it; otherwise Spent stays blank.
 */
function TotalRow({ className, label, totals, node, opts }) {
  const spent = opts.remaining ? spentOf(node) : null;
  return (
    <tr className={className}>
      <th scope="row">{label}</th>
      <td className="num">{peso(totals.implementing_agency)}</td>
      <td className="num">{peso(totals.funder)}</td>
      <td className="num">{peso(totals.total)}</td>
      {opts.remaining ? (
        <>
          <td className="num">{peso(spent)}</td>
          <RemainingCell value={Math.round((Number(totals.total) - spent) * 100) / 100} />
        </>
      ) : <td />}
      {opts.setup && <td />}
    </tr>
  );
}

/** One line item: name, Form B computation, the two amounts, spent (and remaining), actions. */
function ItemRow({ item, inSub, opts }) {
  const { setup, onOpen } = opts;
  const used = setup?.used_line_items.includes(item.id);
  const over = item.spent > item.allocated;
  return (
    <tr className={`fb-item${inSub ? ' in-sub' : ''}`}>
      <td>
        <div>{item.name}</div>
        {item.computation && <div className="fb-computation">{item.computation}</div>}
      </td>
      <td className="num">{peso(item.implementing_agency_amount)}</td>
      <td className="num">{peso(item.funder_amount)}</td>
      <td className="num">{peso(item.allocated)}</td>
      <td className="num">
        {peso(item.spent)}
        {over && <div><ProgramPill value="Over budget" tone="risk" icon="✕" /></div>}
      </td>
      {opts.remaining && <RemainingCell value={Math.round((Number(item.allocated) - Number(item.spent)) * 100) / 100} />}
      {setup && (
        <td className="pd-actions">
          {used && <span className="project-code" title="It has expenses, payroll or purchase requests">🔒 In use </span>}
          <RowMenu name={item.name} options={menuOptions(lineItemActions(item, { used }), onOpen)} />
        </td>
      )}
    </tr>
  );
}

/**
 * PS or MOOE in Form B order: Direct then Indirect cost, each with its
 * expense groups (and optional sub-groups), sub-totals per source at every
 * level.
 */
function FormBSection({ section, opts }) {
  const span = 5 + (opts.remaining ? 1 : 0) + (opts.setup ? 1 : 0);
  return (
    <>
      <tr className="fb-section">
        <th scope="rowgroup" colSpan={span}>{section.label} ({section.type})</th>
      </tr>
      {section.cost_types.map((cost) => (
        <Fragment key={cost.cost_type}>
          <tr className="fb-cost"><td colSpan={span}>{cost.label}</td></tr>
          {cost.groups.map((group) => (
            <Fragment key={group.name}>
              <tr className="fb-group"><td colSpan={span}>{group.name}</td></tr>
              {group.items.map((item) => <ItemRow key={item.id} item={item} opts={opts} />)}
              {group.subgroups.map((sub) => (
                <Fragment key={sub.name}>
                  <tr className="fb-subgroup"><td colSpan={span}>{sub.name}</td></tr>
                  {sub.items.map((item) => <ItemRow key={item.id} item={item} inSub opts={opts} />)}
                  <TotalRow className="fb-sub" label={`Sub-total, ${sub.name}`} totals={sub.subtotal} node={sub} opts={opts} />
                </Fragment>
              ))}
              <TotalRow className="fb-sub" label={`Sub-total, ${group.name}`} totals={group.subtotal} node={group} opts={opts} />
            </Fragment>
          ))}
          <TotalRow className="fb-sub" label={`Sub-total, ${cost.label}`} totals={cost.subtotal} node={cost} opts={opts} />
        </Fragment>
      ))}
      <TotalRow className="fb-sub-section" label={`Total ${section.type}`} totals={section.subtotal} node={section} opts={opts} />
    </>
  );
}

/**
 * The line-item budget in R&D Form B layout (form_b from
 * ProjectFinancialService::formB): the Program Leader's project page and the
 * Researcher's Budget page.
 *
 * - `setup` (Program Leader only): adds the row menus. Without it the table
 *   is read-only.
 * - `remaining`: adds a Remaining column (allocated − verified spent) and
 *   fills Spent and Remaining on every sub-total row.
 */
export default function FormBTable({ formB, setup = null, onOpen, remaining = false }) {
  const sections = formB?.sections ?? [];
  const labels = sourceLabels(formB?.labels);
  const opts = { setup, onOpen, remaining };
  return (
    <div className="program-scroll">
      <table className="program-table fb-table">
        <caption className="pa-sr-only">Line-item budget in Form B layout</caption>
        <thead>
          <tr>
            <th scope="col">Particulars</th>
            <th scope="col" className="num">{labels.implementing_agency}</th>
            <th scope="col" className="num">{labels.funder}</th>
            <th scope="col" className="num">Total</th>
            <th scope="col" className="num"><Tip>Spent</Tip></th>
            {remaining && <th scope="col" className="num">Remaining</th>}
            {setup && <th scope="col"><span className="pa-sr-only">Actions</span></th>}
          </tr>
        </thead>
        <tbody>
          {sections.map((section) => <FormBSection key={section.type} section={section} opts={opts} />)}
        </tbody>
        {formB?.grand_total && (
          <tfoot>
            <TotalRow className="fb-grand" label="Grand total" totals={formB.grand_total} node={formB} opts={opts} />
          </tfoot>
        )}
      </table>
    </div>
  );
}
