import streamlit as st
import pandas as pd
from datetime import datetime, timedelta
from enum import Enum
from dataclasses import dataclass
from typing import Dict, List, Optional
try:
    from auth import auth_manager
except ImportError:
    st.error("❌ auth.py not found!")
    st.stop()

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
    company: str = "PRA Construction"
    pa_application_no: Optional[str] = None
    bca_notification_no: Optional[str] = None
    status: ApprovalStatus = ApprovalStatus.PENDING
    pa_submitted_date: Optional[datetime] = None
    pa_approved_date: Optional[datetime] = None
    bca_submitted_date: Optional[datetime] = None
    mobilisation_date: Optional[datetime] = None
    notes: Optional[str] = None

class MobilizationManager:
    def __init__(self):
        self.projects: Dict[str, Project] = {}
        self._load_projects()
    
    def _load_projects(self):
        project_data = {
            "PRA-001": {"name": "Msida School Construction", "client": "FTS", "location": "Msida", "company": "PRA Construction"},
            "PRA-002": {"name": "Gov Lot 1", "client": "Housing", "location": "Government Lot 1", "company": "PRA Construction"},
            "PRA-003": {"name": "Gov Lot 2", "client": "Housing", "location": "Government Lot 2", "company": "PRA Construction"},
            "PRA-004": {"name": "Gov Lot 3", "client": "Housing", "location": "Government Lot 3", "company": "PRA Construction"},
            "PRA-005": {"name": "Tac Cawla", "client": "Gozo Housing", "location": "Tac Cawla, Gozo", "company": "PRA Gozo Branch"},
            "PRA-006": {"name": "Zebbug Council", "client": "Zebbug Council", "location": "Zebbug, Gozo", "company": "PRA Gozo Branch"},
            "PRA-007": {"name": "Msida School Finishes", "client": "FTS", "location": "Msida", "company": "PRA Construction"}
        }
        
        for proj_id, data in project_data.items():
            self.projects[proj_id] = Project(**data)
    
    def update_project(self, project_id: str, **kwargs):
        if project_id in self.projects:
            for key, value in kwargs.items():
                if hasattr(self.projects[project_id], key):
                    setattr(self.projects[project_id], key, value)
            return True
        return False

@st.cache_resource
def get_manager():
    return MobilizationManager()

def show_login_page():
    st.title("🔐 PRA Construction - Mobilization Tracker")
    st.markdown("---")
    st.markdown("""
    <div style='text-align: center;'>
        <h2 style='color: #1f77b4;'>Site Mobilization Status Tracking</h2>
        <p>PA Approvals | BCA Notifications | Mobilization Timeline</p>
    </div>
    """, unsafe_allow_html=True)
    
    col1, col2 = st.columns(2)
    with col1:
        username = st.text_input("👤 Username")
    with col2:
        password = st.text_input("🔑 Password", type="password")
    
    if st.button("🚀 Login", type="primary"):
        if auth_manager.login(username, password):
            st.session_state['authenticated'] = True
            st.session_state['username'] = username
            st.session_state['role'] = auth_manager.users[username].role
            st.session_state['company'] = auth_manager.users[username].company
            st.rerun()
        else:
            st.error("❌ Invalid credentials")

def show_header(user):
    col1, col2, col3 = st.columns([1, 2, 1])
    with col1:
        st.title("🏗️ PRA Mobilization Tracker")
    with col2:
        st.markdown(f"**👤 {user.name}** | 🏢 **{user.company}** | 🎭 **{user.role.title()}**")
    with col3:
        if st.button("🚪 Logout"):
            auth_manager.logout()
            for key in list(st.session_state.keys()):
                del st.session_state[key]
            st.rerun()

def show_dashboard(manager, role, company):
    st.header("📊 Dashboard")
    projects = manager.projects.values()
    
    progress_data = []
    for project in projects:
        progress = 0
        if project.pa_submitted_date: progress += 25
        if project.pa_approved_date: progress += 25
        if project.bca_submitted_date: progress += 25
        if project.mobilisation_date: progress += 25
        
        progress_data.append({
            "Project": project.project_name[:20],
            "Progress": progress,
            "Status": project.status.value,
            "Client": project.client
        })
    
    df = pd.DataFrame(progress_data)
    col1, col2 = st.columns(2)
    with col1:
        st.subheader("📈 Progress")
        st.bar_chart(df.set_index("Project")["Progress"])
    with col2:
        st.subheader("📋 Status")
        st.bar_chart(df["Status"].value_counts())

def show_projects(manager, role, company):
    st.header("📋 Projects")
    project_list = list(manager.projects.keys())
    selected_id = st.selectbox("Select Project", project_list)
    project = manager.projects[selected_id]
    
    col1, col2, col3, col4 = st.columns(4)
    with col1:
        st.metric("PA", project.pa_application_no or "Pending")
        pa_no = st.text_input("PA No", key=f"pa_{selected_id}")
        if st.button("Submit PA", key=f"pa_sub_{selected_id}"):
            manager.update_project(selected_id, pa_application_no=pa_no, pa_submitted_date=datetime.now())
            st.rerun()
    
    with col2:
        st.metric("Status", project.status.value)
        if st.button("PA Approved", key=f"pa_app_{selected_id}"):
            manager.update_project(selected_id, pa_approved_date=datetime.now(), status=ApprovalStatus.APPROVED)
            st.rerun()
    
    with col3:
        st.metric("BCA", project.bca_notification_no or "Pending")
        bca_no = st.text_input("BCA No", key=f"bca_{selected_id}")
        if st.button("Submit BCA", key=f"bca_sub_{selected_id}"):
            manager.update_project(selected_id, bca_notification_no=bca_no, bca_submitted_date=datetime.now())
            st.rerun()
    
    with col4:
        st.metric("Mobilized", "Yes" if project.mobilisation_date else "No")
        if st.button("Mobilized", key=f"mob_{selected_id}"):
            manager.update_project(selected_id, mobilisation_date=datetime.now())
            st.rerun()

def main():
    if 'authenticated' not in st.session_state:
        st.session_state.authenticated = False
    
    if st.session_state.authenticated:
        user = auth_manager.get_current_user()
        show_header(user)
        
        tab1, tab2 = st.tabs(["📊 Dashboard", "📋 Projects"])
        manager = get_manager()
        
        with tab1:
            show_dashboard(manager, user.role, user.company)
        with tab2:
            show_projects(manager, user.role, user.company)
    elif auth_manager.login_ui():
        st.stop()
    else:
        show_login_page()

if __name__ == "__main__":
    main()
