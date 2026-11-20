import { usePage } from '@inertiajs/vue3';
import { ChartColumn, Images, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { index as adminReports } from '@/routes/admin/reports';
import { index as history } from '@/routes/generations';
import type { NavItem } from '@/types';

/** The app's primary navigation, shared by the sidebar and header layouts. */
export function useMainNavItems() {
    const page = usePage();

    return computed<NavItem[]>(() => [
        { title: 'Generate', href: dashboard(), icon: Sparkles },
        { title: 'History', href: history(), icon: Images },
        ...(page.props.auth.user?.is_admin
            ? [
                  {
                      title: 'Admin report',
                      href: adminReports(),
                      icon: ChartColumn,
                  },
              ]
            : []),
    ]);
}
