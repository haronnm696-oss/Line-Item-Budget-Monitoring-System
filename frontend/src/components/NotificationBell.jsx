import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ENDPOINTS } from '../api/endpoints';
import { apiGet, apiWrite } from '../api/http';
import { useAuth } from '../context/AuthContext';
import { formatDateTime } from '../lib/datetime';
import { normalizeRole } from '../lib/roles';
import { notificationTarget, unreadBadge } from '../lib/notifications';

const POLL_MS = 60000;

const BellIcon = (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6" />
    <path d="M10 19a2.2 2.2 0 0 0 4 0" />
  </svg>
);

/**
 * Header bell for every role: unread badge (polled), a dropdown with the
 * latest notifications, "Mark all as read", and a click that opens the
 * record (or the role's review page when the role cannot open that page).
 */
export default function NotificationBell() {
  const { user } = useAuth();
  const role = normalizeRole(user);
  const navigate = useNavigate();
  const rootRef = useRef(null);

  const [open, setOpen] = useState(false);
  const [unread, setUnread] = useState(0);
  const [list, setList] = useState({ status: 'idle', items: [] });
  const [saveError, setSaveError] = useState('');
  const [marking, setMarking] = useState(false);

  const loadList = useCallback(async () => {
    setList((current) => ({ status: 'loading', items: current.items }));
    try {
      const data = await apiGet(ENDPOINTS.notifications);
      setList({ status: 'ready', items: Array.isArray(data?.notifications) ? data.notifications : [] });
      setUnread(Number(data?.meta?.unread) || 0);
    } catch (error) {
      setList({ status: 'error', items: [], message: error?.message });
    }
  }, []);

  // Poll the unread count while the tab is visible, and on focus.
  const userId = user?.id;
  useEffect(() => {
    if (!userId) return undefined;
    let alive = true;
    const refresh = () => apiGet(ENDPOINTS.notificationsUnread)
      .then((data) => { if (alive) setUnread(Number(data?.unread) || 0); })
      .catch(() => {}); // The badge is a convenience; a failed count keeps the last value.
    refresh();
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') refresh();
    }, POLL_MS);
    window.addEventListener('focus', refresh);
    return () => {
      alive = false;
      window.clearInterval(timer);
      window.removeEventListener('focus', refresh);
    };
  }, [userId]);

  useEffect(() => {
    if (!open) return undefined;
    const onPointerDown = (event) => {
      if (!rootRef.current?.contains(event.target)) setOpen(false);
    };
    const onKeyDown = (event) => {
      if (event.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  function toggle() {
    const next = !open;
    setOpen(next);
    setSaveError('');
    if (next) loadList();
  }

  async function openItem(item) {
    if (!item.read) {
      try {
        await apiWrite('patch', ENDPOINTS.notificationRead(item.id));
        setList((current) => ({
          ...current,
          items: current.items.map((row) => (row.id === item.id ? { ...row, read: true } : row)),
        }));
        setUnread((count) => Math.max(0, count - 1));
      } catch {
        // Opening the record matters more than the read mark.
      }
    }
    setOpen(false);
    navigate(notificationTarget(role, item.link));
  }

  async function markAllRead() {
    setMarking(true);
    setSaveError('');
    try {
      await apiWrite('patch', ENDPOINTS.notificationsReadAll);
      setList((current) => ({ ...current, items: current.items.map((row) => ({ ...row, read: true })) }));
      setUnread(0);
    } catch (error) {
      setSaveError(error?.message || 'Could not save. Please try again.');
    } finally {
      setMarking(false);
    }
  }

  const badge = unreadBadge(unread);

  return (
    <div className="notif-bell" ref={rootRef}>
      <button
        type="button"
        className="program-icon-btn"
        title="Notifications"
        aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={toggle}
      >
        {BellIcon}
        {badge && <span className="notif-badge" aria-hidden="true">{badge}</span>}
      </button>

      {open && (
        <div className="notif-panel" role="dialog" aria-label="Notifications">
          <div className="notif-head">
            <b>Notifications</b>
            <button
              type="button"
              className="notif-mark-all"
              onClick={markAllRead}
              disabled={marking || unread === 0}
            >
              {marking ? 'Marking…' : 'Mark all as read'}
            </button>
          </div>
          {saveError && <p className="notif-error" role="alert">{saveError}</p>}

          {list.status === 'loading' && list.items.length === 0 && (
            <p className="notif-state" role="status">Loading notifications…</p>
          )}
          {list.status === 'error' && (
            <div className="notif-state" role="alert">
              <p>{list.message || 'Could not load this data. Please try again.'}</p>
              <button type="button" className="notif-mark-all" onClick={loadList}>Try again</button>
            </div>
          )}
          {list.status === 'ready' && list.items.length === 0 && (
            <p className="notif-state">No notifications yet.</p>
          )}

          {list.items.length > 0 && (
            <ul className="notif-list">
              {list.items.map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    className={`notif-item${item.read ? '' : ' is-unread'}`}
                    onClick={() => openItem(item)}
                  >
                    <span className="notif-item-title">
                      {!item.read && (
                        <span className="notif-new">
                          <span className="notif-new-dot" aria-hidden="true" />
                          New
                        </span>
                      )}
                      {item.title}
                    </span>
                    <span className="notif-item-msg">{item.message}</span>
                    <span className="notif-item-time">{formatDateTime(item.created_at)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
