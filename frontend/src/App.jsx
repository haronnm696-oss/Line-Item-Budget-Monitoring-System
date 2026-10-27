import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import ProtectedRoute from './routes/ProtectedRoute';
import AppLayout from './layouts/AppLayout';
import Login from './pages/Login';
import ResetPassword from './pages/ResetPassword';
import Register from './pages/Register';
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
import Users from './pages/Users';

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route path="/reset-password" element={<ResetPassword />} />

          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route path="/dashboard" element={<ProgramLeaderDashboard />} />
              <Route path="/program-leader-dashboard" element={<ProgramLeaderDashboard />} />
              <Route path="/dashboard/program-leader" element={<ProgramLeaderDashboard />} />
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
              <Route path="/users" element={<Users />} />
            </Route>
          </Route>

          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
