export interface User { id:number; name:string; phone:string; roles:{id:number;name:string;slug:string}[]; permissions:string[] }
export interface Session { id:number; title:string; prison:string; location:string; participant_count:number; service_date:string; start_time:string; end_time:string; status:'scheduled'|'cancelled'; version:number; assignments:any[]; invitations:any[]; events:any[] }
