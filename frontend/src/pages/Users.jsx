import { useState } from 'react';
import Chip from '../components/Chip';
import AddUserModal from '../components/AddUserModal';
import AssignProjectModal from '../components/AssignProjectModal';
import {
  systemUsers as mockSystemUsers,
  projectAssignments as mockProjectAssignments,
  projects as mockProjects,
  CURRENT_PROJECT,
} from '../data/mockData';

const CAPABILITIES = [
  ['View dashboards & reports', '✓ all projects', '✓ own projects', '✓ assigned', '✓ assigned', '✓ assigned'],
  ['Record expenses', '—', '—', '✓', '—', '—'],
  ['Compute payroll', '—', '—', '✓', '—', '—'],
  ['Verify / reject expenses', '—', '—', '—', '—', '✓'],
  ['Approve payroll', '—', '✓', '—', '—', '—'],
  ['Manage projects & line items', '✓', '✓ own', '—', '—', '—'],
  ['View audit trail', '✓', '✓', '—', '—', '✓'],
];

const ROLE_DESCRIPTIONS = {
  'Program Leader': 'Oversees multiple research projects; monitors macro-level spending.',
  'Project Leader': 'Manages a specific project; tracks fund utilization and generates reports.',
  'Research Assistant': 'Encodes financial data, records expenses, computes payroll.',
  Researcher: 'Views financial data and itemized logs for designated work.',
  'Finance/Admin Staff': 'Verifies transactions and supports final reporting.',
};

export default function Users() {
  const [systemUsers, setSystemUsers] = useState(mockSystemUsers);
  const [projectAssignments, setProjectAssignments] = useState(mockProjectAssignments);
  const [activeTab, setActiveTab] = useState('team');
  const [addUserOpen, setAddUserOpen] = useState(false);
  const [assignOpen, setAssignOpen] = useState(false);
  const [toast, setToast] = useState(null);

  // Filtered views
  const currentProjectId = CURRENT_PROJECT.id;
  const teamAssignments = projectAssignments.filter((a) => a.projectId === currentProjectId);
  const teamMembers = teamAssignments.map((a) => {
    const u = systemUsers.find((user) => user.id === a.userId);
    return u ? { ...u, roleOnProject: a.role, assignedOnProject: a.assigned } : null;
  }).filter(Boolean);

  const allUsersView = systemUsers.map((u) => {
    const userAssignments = projectAssignments.filter((a) => a.userId === u.id);
    return { ...u, projectCount: userAssignments.length };
  });

  function showToast(msg) {
    setToast({ msg });
    setTimeout(() => setToast(null), 3500);
  }

  // ---------- Add user ----------
  function handleAddUser(newUser) {
    setSystemUsers((prev) => [...prev, newUser]);
    setAddUserOpen(false);
    showToast(`User "${newUser.name}" added to the system.`);
  }

  // ---------- Assign to project ----------
  function handleAssign(assignment) {
    // Check for duplicate assignment
    const exists = projectAssignments.some(
      (a) => a.userId === assignment.userId && a.projectId === assignment.projectId
    );
    if (exists) {
      setToast('This user is already assigned to that project.');
      setAssignOpen(false);
      return;
    }
    setProjectAssignments((prev) => [...prev, assignment]);
    setAssignOpen(false);
    showToast('User assigned to project successfully.');
  }

  // ---------- Status toggle ----------
  function toggleStatus(userId) {
    setSystemUsers((prev) =>
      prev.map((u) =>
        u.id === userId ? { ...u, status: u.status === 'Active' ? 'Inactive' : 'Active' } : u
      )
    );
    const u = systemUsers.find((user) => user.id === userId);
    const newStatus = u.status === 'Active' ? 'Inactive' : 'Active';
    showToast(`${u.name} marked as ${newStatus}.`);
  }

  // ---------- Deactivate ----------
  function deactivateUser(userId) {
    setSystemUsers((prev) =>
      prev.map((u) => (u.id === userId ? { ...u, status: 'Inactive' } : u))
    );
    const u = systemUsers.find((user) => user.id === userId);
    showToast(`${u.name} deactivated.`);
  }

  // ---------- Remove from project ----------
  function removeFromProject(userId, projectId) {
    setProjectAssignments((prev) => prev.filter((a) => !(a.userId === userId && a.projectId === projectId)));
    const u = systemUsers.find((user) => user.id === userId);
    const p = mockProjects.find((proj) => proj.id === projectId);
    showToast(`${u.name} removed from ${p ? p.title : 'project'}.`);
  }

  // ---------- Edit role on project ----------
  function editRoleOnProject(userId, projectId, newRole) {
    setProjectAssignments((prev) =>
      prev.map((a) => (a.userId === userId && a.projectId === projectId ? { ...a, role: newRole } : a))
    );
    const u = systemUsers.find((user) => user.id === userId);
    showToast(`${u.name}'s role updated to ${newRole}.`);
  }

  // ---------- Row action menu ----------
  function openRowMenu(userId, projectId, currentRole) {
    // Simple inline action - could be extended with a dropdown component
    const action = window.prompt(
      `Select action for user:\n1. Edit Role (current: ${currentRole})\n2. Deactivate Account\n3. Remove from Project\n\nType 1, 2, or 3:`
    );
    if (action === '1') {
      const newRole = window.prompt('Enter new role (Project Leader, Research Assistant, Researcher, Finance/Admin Staff):');
      if (newRole) editRoleOnProject(userId, projectId, newRole);
    } else if (action === '2') {
      if (window.confirm('Deactivate this user account?')) {
        deactivateUser(userId);
      }
    } else if (action === '3') {
      if (window.confirm('Remove this user from the project?')) {
        removeFromProject(userId, projectId);
      }
    }
  }

  const statusChipProps = (status) => {
    const tone = status === 'Active' ? 'ok' : 'neutral';
    return { tone, label: status };
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Users &amp; roles</h2>
          <p>Roles are assigned per project — a person can hold different roles on different projects.</p>
        </div>
        <div className="actions" style={{ display: 'flex', gap: 8 }}>
          <button className="btn" onClick={() => setAddUserOpen(true)}>Add user</button>
          <button className="btn primary" onClick={() => setAssignOpen(true)}>Assign to project</button>
        </div>
      </div>

      {/* Toast */}
      {toast && (
        <div className="banner toast" style={{ boxShadow: '0 4px 12px rgba(34,29,26,.18)' }}>
          {toast.msg}
        </div>
      )}

      {/* Tabs */}
      <div className="tabs">
        <button
          className={activeTab === 'team' ? 'active' : ''}
          onClick={() => setActiveTab('team')}
        >
          SAGIP-Lawa team
        </button>
        <button
          className={activeTab === 'all' ? 'active' : ''}
          onClick={() => setActiveTab('all')}
        >
          All system users
        </button>
        <button
          className={activeTab === 'roles' ? 'active' : ''}
          onClick={() => setActiveTab('roles')}
        >
          Role definitions
        </button>
      </div>

      {/* ---------- TAB: SAGIP-Lawa team ---------- */}
      {activeTab === 'team' && (
        <div className="panel wide-scroll">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Employee ID</th>
                <th>Position</th>
                <th>Role on this project</th>
                <th>Assigned</th>
                <th>Status</th>
                <th className="r">Actions</th>
              </tr>
            </thead>
            <tbody>
              {teamMembers.map((m) => (
                <tr key={m.email}>
                  <td>
                    <b>{m.name}</b><br />
                    <small style={{ color: 'var(--muted)' }}>{m.email}</small>
                  </td>
                  <td className="num">{m.empId}</td>
                  <td>{m.position}</td>
                  <td>{m.roleOnProject}</td>
                  <td className="num">{m.assignedOnProject}</td>
                  <td>
                    <span
                      style={{ cursor: 'pointer', textTransform: 'capitalize', display: 'inline-block' }}
                      onClick={() => toggleStatus(m.id)}
                    >
                      <Chip tone={statusChipProps(m.status).tone}>{m.status}</Chip>
                    </span>
                  </td>
                  <td className="r">
                    <button
                      className="link-btn"
                      onClick={() => openRowMenu(m.id, currentProjectId, m.roleOnProject)}
                      title="Actions"
                    >
                      ⋮
                    </button>
                  </td>
                </tr>
              ))}
              {teamMembers.length === 0 && (
                <tr>
                  <td colSpan={7} style={{ textAlign: 'center', color: 'var(--muted)', padding: 24 }}>
                    No team members assigned to this project.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {/* ---------- TAB: All system users ---------- */}
      {activeTab === 'all' && (
        <div className="panel wide-scroll">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Employee ID</th>
                <th>Position</th>
                <th>Default Role</th>
                <th>Project Count</th>
                <th>Status</th>
                <th className="r">Actions</th>
              </tr>
            </thead>
            <tbody>
              {allUsersView.map((u) => (
                <tr key={u.email}>
                  <td><b>{u.name}</b></td>
                  <td>{u.email}</td>
                  <td className="num">{u.empId}</td>
                  <td>{u.position}</td>
                  <td>{u.role}</td>
                  <td className="num">{u.projectCount}</td>
                  <td>
                    <span
                      style={{ cursor: 'pointer', textTransform: 'capitalize', display: 'inline-block' }}
                      onClick={() => toggleStatus(u.id)}
                    >
                      <Chip tone={statusChipProps(u.status).tone}>{u.status}</Chip>
                    </span>
                  </td>
                  <td className="r">
                    <button
                      className="link-btn"
                      onClick={() => openRowMenu(u.id, null, u.role)}
                      title="Actions"
                    >
                      ⋮
                    </button>
                  </td>
                </tr>
              ))}
              {allUsersView.length === 0 && (
                <tr>
                  <td colSpan={8} style={{ textAlign: 'center', color: 'var(--muted)', padding: 24 }}>
                    No system users found.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {/* ---------- TAB: Role definitions ---------- */}
      {activeTab === 'roles' && (
        <div className="panel" id="role-definitions">
          <div className="panel-head">
            <h3>What each role can do</h3>
            <span className="hint">Permission matrix for LIB system roles</span>
          </div>
          <div className="wide-scroll">
            <table>
              <thead>
                <tr>
                  <th>Capability</th>
                  <th>Program Leader</th>
                  <th>Project Leader</th>
                  <th>Research Assistant</th>
                  <th>Researcher</th>
                  <th>Finance/Admin</th>
                </tr>
              </thead>
              <tbody>
                {CAPABILITIES.map((row) => (
                  <tr key={row[0]}>
                    {row.map((cell, i) => <td key={i}>{cell}</td>)}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div style={{ padding: 16 }}>
            <h4 style={{ fontSize: '13px', marginBottom: 8, color: 'var(--maroon-soft)' }}>Role descriptions</h4>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
              {Object.entries(ROLE_DESCRIPTIONS).map(([role, desc]) => (
                <div key={role} className="panel" style={{ padding: 12 }}>
                  <b>{role}</b>
                  <p style={{ fontSize: '12px', color: 'var(--muted)', marginTop: 4 }}>{desc}</p>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Modals */}
      <AddUserModal open={addUserOpen} onSave={handleAddUser} onClose={() => setAddUserOpen(false)} />
      <AssignProjectModal
        open={assignOpen}
        users={systemUsers}
        projects={mockProjects}
        onSave={handleAssign}
        onClose={() => setAssignOpen(false)}
      />
    </>
  );
}
