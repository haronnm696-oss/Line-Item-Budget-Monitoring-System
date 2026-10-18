import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Chip, { statusTone } from '../components/Chip';
import NewProjectModal from '../components/NewProjectModal';
import UtilBar from '../components/UtilBar';
import { peso } from '../components/money';
import { getWithFallback } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { projects as mockProjects } from '../data/mockData';
import { useAuth } from '../context/AuthContext';

export default function Projects() {
  const [projects, setProjects] = useState([]);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('All');
  const [agency, setAgency] = useState('All');
  const [program, setProgram] = useState('All Projects — MSU–Main Campus');
  const [modalOpen, setModalOpen] = useState(false);
  const navigate = useNavigate();
  const { user } = useAuth();

  useEffect(() => {
    getWithFallback(ENDPOINTS.projects, mockProjects).then(setProjects);
  }, []);

  const agencies = ['All', ...new Set(mockProjects.map((p) => p.agency))];
  const statuses = ['All', 'Planning', 'Ongoing', 'Completed', 'Terminated'];
  const programs = [
    'All Projects — MSU–Main Campus',
    ...new Set(mockProjects.map((p) => p.program).filter(Boolean)),
  ];

  const filtered = projects.filter((p) => {
    const matchesProgram =
      program === 'All Projects — MSU–Main Campus' || p.program === program;
    return (
      matchesProgram &&
      (status === 'All' || p.status === status) &&
      (agency === 'All' || p.agency === agency) &&
      (p.title.toLowerCase().includes(search.toLowerCase()) || p.code.toLowerCase().includes(search.toLowerCase()))
    );
  });

  function handleNewProject(newProject) {
    setProjects((prev) => [newProject, ...prev]);
    setModalOpen(false);
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Projects</h2>
          <p>Externally funded research projects you have access to</p>
        </div>
        <div className="actions" style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <select
            value={program}
            onChange={(e) => setProgram(e.target.value)}
            style={{ border: '1px solid var(--line-strong)', borderRadius: '6px', padding: '7px 10px', font: 'inherit', fontSize: '13px', background: 'var(--paper)', color: 'var(--ink)' }}
          >
            {programs.map((p) => (
              <option key={p} value={p}>{p}</option>
            ))}
          </select>
          {user.role === 'PRG' && (
            <button className="btn primary" onClick={() => setModalOpen(true)}>
              New project
            </button>
          )}
        </div>
      </div>

      <div className="filter-row">
        <input
          type="search"
          placeholder="Search by title or code"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select value={status} onChange={(e) => setStatus(e.target.value)}>
          {statuses.map((s) => (
            <option key={s} value={s}>{s === 'All' ? 'All statuses' : s}</option>
          ))}
        </select>
        <select value={agency} onChange={(e) => setAgency(e.target.value)}>
          {agencies.map((a) => (
            <option key={a} value={a}>{a === 'All' ? 'All funding agencies' : a}</option>
          ))}
        </select>
      </div>

      <div className="panel wide-scroll">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Project title</th>
              <th>Program</th>
              <th>Funding agency</th>
              <th className="r">Approved budget</th>
              <th>Utilization</th>
              <th>Period</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((p) => (
              <tr key={p.id} onClick={() => navigate(`/projects/${p.id}`)} style={{ cursor: 'pointer' }}>
                <td className="num mono-b">{p.code}</td>
                <td>{p.title}</td>
                <td>{p.program || '—'}</td>
                <td>{p.agency}</td>
                <td className="r num">{peso(p.budget)}</td>
                <td><UtilBar pct={(p.spent / p.budget) * 100} /></td>
                <td className="num">{p.period}</td>
                <td><Chip tone={statusTone(p.status)}>{p.status}</Chip></td>
              </tr>
            ))}
            {filtered.length === 0 && (
              <tr>
                <td colSpan={8} style={{ textAlign: 'center', color: 'var(--muted)', padding: 24 }}>
                  No projects match these filters.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <NewProjectModal open={modalOpen} onSave={handleNewProject} onClose={() => setModalOpen(false)} />
    </>
  );
}
