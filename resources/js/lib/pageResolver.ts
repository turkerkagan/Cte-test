import PageFailure from '../components/PageFailure';

export const pageLoaders = {
    Workspace: () => import('../pages/Workspace'),
    AdminWorkspace: () => import('../pages/AdminWorkspace'),
    AccountRequest: () => import('../pages/AccountRequest'),
    PortalHealth: () => import('../pages/PortalHealth'),
    PersonalMessages: () => import('../pages/PersonalMessages'),
    PersonDocuments: () => import('../pages/PersonDocuments'),
    RoomLists: () => import('../pages/RoomLists'),
    BulkStatus: () => import('../pages/BulkStatus'),
    ExcelMerge: () => import('../pages/ExcelMerge'),
    Comparison: () => import('../pages/Comparison'),
    Statistics: () => import('../pages/Statistics'),
    Chat: () => import('../pages/Chat'),
    Meals: () => import('../pages/Meals'),
    Institution: () => import('../pages/Institution'),
    StaffTree: () => import('../pages/StaffTree'),
    WorkflowList: () => import('../pages/WorkflowList'),
    WorkflowEditor: () => import('../pages/WorkflowEditor'),
    WorkflowPrint: () => import('../pages/WorkflowPrint'),
    PlacementEditor: () => import('../pages/PlacementEditor'),
    Statuses: () => import('../pages/Statuses'),
    Housing: () => import('../pages/Housing'),
    People: () => import('../pages/People'),
    PersonEditor: () => import('../pages/PersonEditor'),
    Foundation: () => import('../pages/Foundation'),
    Login: () => import('../pages/Login'),
    Password: () => import('../pages/Password'),
    Organization: () => import('../pages/Organization'),
    Users: () => import('../pages/Users'),
    UserEditor: () => import('../pages/UserEditor'),
    PagePolicies: () => import('../pages/PagePolicies'),
    Modules: () => import('../pages/Modules'),
    ModuleDesigner: () => import('../pages/ModuleDesigner'),
    OptionLists: () => import('../pages/OptionLists'),
    MenuEditor: () => import('../pages/MenuEditor'),
    ModulePrint: () => import('../pages/ModulePrint'),
    ModuleRecords: () => import('../pages/ModuleRecords'),
    ModuleRecordEditor: () => import('../pages/ModuleRecordEditor'),
};

export async function resolvePage(name: string) {
    const loader = pageLoaders[name as keyof typeof pageLoaders];
    if (!Object.hasOwn(pageLoaders, name) || !loader) return PageFailure;
    try {
        return (await loader()).default;
    } catch {
        return PageFailure;
    }
}
