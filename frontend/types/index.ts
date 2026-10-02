export interface User {
  id: number;
  name: string;
  phone: string;
  roles: { id: number; name: string; slug: string }[];
  permissions: string[];
  must_change_password: boolean;
}
export interface Session {
  id: number;
  title: string;
  class_name?: string | null;
  color?: string;
  prison: string;
  prison_id?: number | null;
  prison_address?: string | null;
  location: string;
  participant_count: number;
  service_date: string;
  start_time: string;
  end_time: string;
  status: "scheduled" | "cancelled";
  version: number;
  original_teacher_count?: number;
  assignments: any[];
  invitations: any[];
  events: any[];
}
