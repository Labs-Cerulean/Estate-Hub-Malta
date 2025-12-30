import streamlit as st
import pandas as pd
from datetime import datetime, timedelta
from enum import Enum
from dataclasses import dataclass
from typing import Dict, List, Optional
try:
    from auth import auth_manager
except ImportError:
    st.error("❌ auth.py not found! Please ensure auth.py is in the same directory.")
    st.stop()

# Page config
st.set_page_config(
    page_title="PRA Mobilization Tracker",
    page_icon="🏗️",
    layout="wide",
    initial_sidebar_state="expanded"
)

class ApprovalStatus(Enum):
    PENDING = "Pending"
    SUBMITTED = "Submitted"
    APPROVED = "Approved"
    REJECTED = "Rejected"
    NOT_REQUIRED = "Not Required"

@dataclass
class Project:
    project_id: str
    project_name: str
    client: str
    location: str
    company: str = "PRA Construction"  # For company filtering
    pa_application_no: Optional[str] = None
    bca_notification_no: Optional[str] = None
    status: ApprovalStatus = ApprovalStatus.PENDING
    pa_submitted_date: Optional[datetime] = None
    pa_approved_date: Optional[datetime] = None
    bca_submitted_date: Optional[datetime] = None
    mobilisation_date: Optional[datetime] = None
    estimated_duration_days: int = 90
    assigned_manager: Optional[str] = None
    notes: Optional[str] = None

class MobilizationManager:
    def __init__(self):
        self.projects: Dict[str, Project] = {}
        self._load_initial_projects()
    
    @st.cache_data
    def _load_initial_projects(self):
        """Load active projects from your payments[file:140]"""
        project_data = {
            "PRA-001": {
                "name": "Msida School Construction", 
                "client": "FTS", 
                "location": "Msida",
                "company": "PRA Construction"
            },
            "PRA-002": {
                "name": "Gov Lot 1", 
                "client": "Housing", 
                "location": "Government Lot 1",
                "company": "PRA Construction"
            },
            "PRA-003": {
                "name": "Gov Lot 2", 
                "client": "Housing", 
                "location": "Government Lot 2", 
                "company": "PRA Construction"
            },
            "PRA-004": {
                "name": "Gov Lot 3", 
                "client": "Housing", 
                "location": "Government Lot 3",
                "company": "PRA Construction"
            },
            "PRA-005": {
                "name": "Tac Cawla", 
                "client": "Gozo Housing", 
                "location": "Tac Cawla, Gozo",
                "company": "PRA Gozo Branch"
            },
            "PRA-006": {
                "name": "Zebbug Council", 
                "client": "Zebbug Council", 
                "location": "Zebbug, Gozo",
                "company": "PRA Gozo Branch"
            },
            "PRA-007": {
                "name": "Msida School Finishes", 
                "client": "FTS", 
                "location": "Msida",
                "company": "PRA Construction"
            }
        }
        
        for proj_id, data in project_data.items():
            self.projects[proj_id] = Project(
                project_id=proj_id,
                project_name=data["name"],
                client=data["client"],
                location=data["location"],
                company=data["company"]
            )[file:140]
    
    def update_project(self, project_id: str, **kwargs):
        """Update project data"""
        if project_id in self.projects:
            for key, value in kwargs.items():
                if hasattr(self.projects[project_id], key):
                    setattr(self.projects[project_id], key, value)
            return True
        return False
    
    def get_projects_by_company(self, company: str) -> List[Project]:
        """Filter projects by company"""
        return [p for p in self.projects.values() if company in p.company]
    
    def get_projects_by_role(self, role: str) -> List[Project]:
        """Filter projects by role access"""
        if role == '
