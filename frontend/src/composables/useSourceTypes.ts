import { Database, FolderOpen, Container } from "lucide-vue-next";
import type { Component } from "vue";

export interface SourceTypeInfo {
  readonly value: string;
  readonly label: string;
  readonly defaultPort: number | null;
  readonly isDb: boolean;
}

export const DB_TYPES: readonly SourceTypeInfo[] = [
  { value: "postgresql", label: "PostgreSQL", defaultPort: 5432, isDb: true },
  { value: "mysql", label: "MySQL", defaultPort: 3306, isDb: true },
  { value: "mariadb", label: "MariaDB", defaultPort: 3306, isDb: true },
  { value: "mongodb", label: "MongoDB", defaultPort: 27017, isDb: true },
  { value: "redis", label: "Redis", defaultPort: 6379, isDb: true },
] as const;

export const FS_TYPES: readonly SourceTypeInfo[] = [
  { value: "directory", label: "Directory", defaultPort: null, isDb: false },
  {
    value: "docker_volume",
    label: "Docker Volume",
    defaultPort: null,
    isDb: false,
  },
] as const;

export const ALL_TYPES: readonly SourceTypeInfo[] = [...DB_TYPES, ...FS_TYPES];

export function sourceIcon(type: string): Component {
  if (type === "directory") return FolderOpen;
  if (type === "docker_volume") return Container;
  return Database;
}

export function findType(value: string): SourceTypeInfo | undefined {
  return ALL_TYPES.find((t) => t.value === value);
}
