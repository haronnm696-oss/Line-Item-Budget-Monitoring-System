import { BrowserRouter, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import ProtectedRoute from './routes/ProtectedRoute';
import RoleRoute from './routes/RoleRoute';
import RoleLayout from './layouts/RoleLayout';
import ComingSoon from './pages/ComingSoon';
import { COMING_SOON_PATHS } from './layouts/roleNav';
import ProgramLayout from './layouts/ProgramLayout';
import Login from './pages/Login';
import ForgotPassword from './pages/ForgotPassword';
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
import LineItemBudget from './pages/leader/LineItemBudget';
import Transactions from './pages/leader/Transactions';
import Submissions from './pages/leader/Submissions';
import MyRequests from './pages/leader/MyRequests';
import ProgramApprovals from './pages/program/ProgramApprovals';
import ProgramProjects from './pages/program/ProgramProjects';
import ProgramProjectDetail from './pages/program/ProgramProjectDetail';
import ProgramReports from './pages/program/ProgramReports';
import ProgramExecutiveReport from './pages/program/ProgramExecutiveReport';
import ProgramAudit from './pages/program/ProgramAudit';
import ProgramUsers from './pages/program/ProgramUsers';
import AssistantExpenses from './pages/assistant/AssistantExpenses';
import AssistantPayroll from './pages/assistant/AssistantPayroll';
import PurchaseRequests from './pages/assistant/PurchaseRequests';
import MySubmissions from './pages/assistant/MySubmissions';
import Documents from './pages/assistant/Documents';
import BalancingQuery from './pages/assistant/BalancingQuery';
import ByRole from './routes/ByRole';
import HomeRoute from './routes/HomeRoute';
import NotFound from './pages/NotFound';

/** A short URL that keeps its ?project= (and other filters). */
function RedirectKeepingQuery({ to }) {
  const { search } = useLocation();
  return <Navigate to={`${to}${search}`} replace />;
}

// Pages inside the shared role shell. Who may open each one is in
// routes/access.js (ROUTE_ROLES).
const ROLE_PAGES = [
  ['/dashboard', <Dashboard />],
  ['/program-leader-dashboard', <Navigate to="/program-dashboard" replace />],
  ['/dashboard/program-leader', <Navigate to="/program-dashboard" replace />],
  ['/project-leader-dashboard', <ProjectLeaderDashboard />],
  ['/dashboard/project-leader', <ProjectLeaderDashboard />],
  ['/research-assistant-dashboard', <ResearchAssistantDashboard />],
  ['/dashboard/research-assistant', <ResearchAssistantDashboard />],
  ['/researcher-dashboard', <ResearcherDashboard />],
  ['/dashboard/researcher', <ResearcherDashboard />],
  ['/dashboard/finance', <FinanceDashboard />],
  ['/projects', <Projects />],
  ['/projects/:id', <ProjectDetail />],
  // Shared paths: the Research Assistant gets their own encoding pages.
  ['/expenses', <ByRole RA={<AssistantExpenses />} other={<Expenses />} />],
  ['/payroll', <ByRole RA={<AssistantPayroll />} other={<Payroll />} />],
  ['/purchase-requests', <PurchaseRequests />],
  ['/my-submissions', <MySubmissions />],
  ['/documents', <Documents />],
  ['/balancing-query', <BalancingQuery />],
  ['/balancing', <RedirectKeepingQuery to="/balancing-query" />],
  ['/reports', <Reports />],
  ['/audit', <AuditTrail />],
  ['/budget', <LineItemBudget />],
  ['/transactions', <Transactions />],
  ['/submissions', <Submissions />],
  ['/my-requests', <MyRequests />],
];

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/reset-password" element={<ResetPassword />} />
          <Route path="/terms" element={<Policy type="terms" />} />
          <Route path="/privacy" element={<Policy type="privacy" />} />

          <Route element={<ProtectedRoute />}>
            {/* Shared role shell for the Project Leader, Research Assistant,
                Finance/Admin and Researcher (same shell as the Program Leader). */}
            <Route element={<RoleLayout />}>
              {/* Every route is guarded by role (routes/access.js); a user
                  who opens another role's route goes to their own dashboard. */}
              {ROLE_PAGES.map(([path, element]) => (
                <Route key={path} path={path} element={<RoleRoute path={path}>{element}</RoleRoute>} />
              ))}
              {COMING_SOON_PATHS.map((path) => (
                <Route key={path} path={path} element={<RoleRoute path={path}><ComingSoon /></RoleRoute>} />
              ))}
            </Route>

            {/* Program Leader-only shell. Other roles are redirected by RoleRoute. */}
            <Route
              element={<RoleRoute allow={['PRG']}><ProgramLayout /></RoleRoute>}
            >
              <Route path="/program-dashboard" element={<ProgramLeaderDashboard />} />
              <Route path="/program-approvals" element={<ProgramApprovals />} />
              <Route path="/program-projects" element={<ProgramProjects />} />
              <Route path="/program-projects/:id" element={<ProgramProjectDetail />} />
              <Route path="/program-reports" element={<ProgramReports />} />
              <Route path="/program-reports/executive" element={<ProgramExecutiveReport />} />
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
