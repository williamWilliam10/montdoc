import { NgModule } from '@angular/core';
import { Routes, RouterModule } from '@angular/router';

import { InstallerComponent } from './installer.component';

const routes: Routes = [
    {
        path: '',
        component: InstallerComponent
    }
];

@NgModule({
    imports: [RouterModule.forChild(routes)],
    exports: [RouterModule],
})
export class InstallerRoutingModule {}
