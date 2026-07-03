import { useState } from 'react';
import { Navbar } from './Navbar';
import { Sidebar } from './Sidebar';

export const MainLayout = ({ children }) => {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);

  return (
    <div className="min-h-screen bg-canvas">
      <Navbar
        onMenuClick={() => setSidebarOpen(!sidebarOpen)}
        onSidebarToggle={() => setSidebarCollapsed(!sidebarCollapsed)}
        sidebarCollapsed={sidebarCollapsed}
      />

      <div className="flex pt-16">
        <Sidebar
          isOpen={sidebarOpen}
          onClose={() => setSidebarOpen(false)}
          collapsed={sidebarCollapsed}
        />

        <main className={`flex-1 p-4 sm:p-6 lg:p-8 transition-all duration-300 ${
          sidebarCollapsed ? 'lg:ml-0' : 'lg:ml-64'
        }`}>
          <div className="max-w-7xl mx-auto">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
};
