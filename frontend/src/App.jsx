import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import ProtectedRoute from './routes/ProtectedRoute';
import RoleRoute from './routes/RoleRoute';
import AppLayout from './layouts/AppLayout';
import ProgramLayout from './layouts/ProgramLayout';
import Login from './pages/Login';
import ResetPassword from './pages/ResetPassword';
import Register from './pages/Register';
import Policy from './pages/Policy';
import Dashboard from './pages/Dashboard';
import ProgramLeaderDashboard from './pages/dashboards/ProgramLeaderDashboard';
import ProjectLeaderDashboard from './pages/dashboards/ProjectLeaderDashboard';
import ResearcherDashboard from './pages/dashboards/ResearcherDashboard';
import ResearchAssistantDashboard from './pages/dashboards/ResearchAssistantDashboard';
import FinanceDashboard from './pages/dashboards/FinanceDashboard';
import Projects from './pages/Projects';
import ProjectDetail from './pages/ProjectDetail';
import Expenses from './pages/Expenses';
import Payroll from './pages/Payroll';
import Reports from './pages/Reports';
import AuditTrail from './pages/AuditTrail';
import ProgramApprovals from './pages/program/ProgramApprovals';
import ProgramProjects from './pages/program/ProgramProjects';
import ProgramReports from './pages/program/ProgramReports';
import ProgramAudit from './pages/program/ProgramAudit';
import ProgramUsers from './pages/program/ProgramUsers';
import HomeRoute from './routes/HomeRoute';
import NotFound from './pages/NotFound';

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route path="/reset-password" element={<ResetPassword />} />
          <Route path="/terms" element={<Policy type="terms" />} />
          <Route path="/privacy" element={<Policy type="privacy" />} />

          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route path="/dashboard" element={<Dashboard />} />
              <Route
                path="/program-leader-dashboard"
                element={<Navigate to="/program-dashboard" replace />}
              />
              <Route
                path="/dashboard/program-leader"
                element={<Navigate to="/program-dashboard" replace />}
              />
              <Route path="/project-leader-dashboard" element={<ProjectLeaderDashboard />} />
              <Route path="/dashboard/project-leader" element={<ProjectLeaderDashboard />} />
              <Route path="/research-assistant-dashboard" element={<ResearchAssistantDashboard />} />
              <Route path="/dashboard/research-assistant" element={<ResearchAssistantDashboard />} />
              <Route path="/researcher-dashboard" element={<ResearcherDashboard />} />
              <Route path="/dashboard/researcher" element={<ResearcherDashboard />} />
              <Route path="/dashboard/project-leader" element={<ProjectLeaderDashboard />} />
              <Route path="/dashboard/research-assistant" element={<ResearchAssistantDashboard />} />
              <Route path="/dashboard/researcher" element={<ResearcherDashboard />} />
              <Route path="/dashboard/finance" element={<FinanceDashboard />} />
              <Route path="/projects" element={<Projects />} />
              <Route path="/projects/:id" element={<ProjectDetail />} />
              <Route path="/expenses" element={<Expenses />} />
              <Route path="/payroll" element={<Payroll />} />
              <Route path="/reports" element={<Reports />} />
              <Route path="/audit" element={<AuditTrail />} />
            </Route>

            {/* Program Leader-only shell. Other roles are redirected by RoleRoute. */}
            <Route
              element={<RoleRoute allow={['PRG']}><ProgramLayout /></RoleRoute>}
            >
              <Route path="/program-dashboard" element={<ProgramLeaderDashboard />} />
              <Route path="/program-approvals" element={<ProgramApprovals />} />
              <Route path="/program-projects" element={<ProgramProjects />} />
              <Route path="/program-projects/:id" element={<ProjectDetail />} />
              <Route path="/program-reports" element={<ProgramReports />} />
              <Route path="/program-audit" element={<ProgramAudit />} />
              <Route path="/program-users" element={<ProgramUsers />} />

              {/* Legacy bookmarks land inside the new shell. */}
              <Route path="/users" element={<Navigate to="/program-users" replace />} />
              <Route path="/approvals" element={<Navigate to="/program-approvals" replace />} />
            </Route>
          </Route>

          <Route path="/" element={<HomeRoute />} />
          <Route path="*" element={<NotFound />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
